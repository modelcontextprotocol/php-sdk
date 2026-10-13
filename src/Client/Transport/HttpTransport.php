<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Client\MonotonicClock;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Wire\McpHeader;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP-based client transport using PSR-18 HTTP client.
 *
 * PSR-18 HTTP clients are auto-discovered if not provided.
 *
 * @phpstan-import-type McpFiber from TransportInterface
 * @phpstan-import-type FiberSuspend from TransportInterface
 * @phpstan-import-type SseEvent from SseParser
 *
 * @phpstan-type SendSuspend array{type: 'send', data: string}
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class HttpTransport extends BaseTransport implements HeaderAwareTransportInterface
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    private ?string $sessionId = null;

    /** @var (callable(string): array<string, string>)|null */
    private $headerCallback;

    /** @var McpFiber|null */
    private ?\Fiber $activeFiber = null;

    /** @var FiberSuspend|SendSuspend|null */
    private ?array $activeSuspend = null;

    /** @var StreamInterface|null Active SSE stream being read */
    private ?StreamInterface $activeStream = null;

    /** The request whose POST response opened the current SSE stream. */
    private int|string|null $sseRequestId = null;

    /** Whether the current SSE stream delivered that request's final response. */
    private bool $sseRequestCompleted = false;

    /** Last event ID received on the current logical SSE stream. */
    private ?string $lastEventId = null;

    /** Reconnection delay requested by the server, in milliseconds. */
    private ?int $serverRetryMs = null;

    /** Number of GET resumption attempts made for the current logical stream. */
    private int $reconnectAttempts = 0;

    /** Clock timestamp, in milliseconds, when the next resumption may start. */
    private ?float $nextReconnectAtMs = null;

    /** @var StreamInterface|null The standalone GET stream the server may send on unprompted */
    private ?StreamInterface $listenStream = null;

    /**
     * Default cap on the bytes buffered while waiting for a complete SSE event.
     */
    public const DEFAULT_MAX_SSE_BUFFER_BYTES = 8 * 1024 * 1024;

    private readonly SseParser $sseParser;

    private readonly SseParser $listenParser;

    private readonly ReconnectPolicy $reconnectPolicy;

    private readonly ClockInterface $clock;

    /**
     * @param string                       $endpoint          The MCP server endpoint URL
     * @param array<string, string>        $headers           Additional headers to send
     * @param ClientInterface|null         $httpClient        PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory    PSR-17 request factory (auto-discovered if null)
     * @param StreamFactoryInterface|null  $streamFactory     PSR-17 stream factory (auto-discovered if null)
     * @param int                          $maxSseBufferBytes Maximum bytes buffered while waiting for a complete
     *                                                        SSE event. A server that never sends the "\n\n" event
     *                                                        delimiter would otherwise grow the buffer without bound
     *                                                        and exhaust client memory; reaching the cap aborts the
     *                                                        stream instead. Raise it for servers that legitimately
     *                                                        emit single events larger than the default.
     * @param bool                         $listen            Open the standalone GET stream after the handshake (2025
     *                                                        revisions only). Needs a PSR-18 client that streams
     *                                                        response bodies; see docs/client/transports.md.
     * @param ReconnectPolicy|null         $reconnectPolicy   How an SSE stream that closed before its response
     *                                                        arrived is resumed (default: 5 attempts, 1-10 s backoff)
     * @param ClockInterface|null          $clock             Clock for scheduling reconnects (default: a monotonic clock)
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxSseBufferBytes = self::DEFAULT_MAX_SSE_BUFFER_BYTES,
        private readonly bool $listen = false,
        ?ReconnectPolicy $reconnectPolicy = null,
        ?ClockInterface $clock = null,
    ) {
        parent::__construct($logger);

        $this->sseParser = new SseParser($maxSseBufferBytes);
        $this->listenParser = new SseParser($maxSseBufferBytes);
        $this->reconnectPolicy = $reconnectPolicy ?? new ReconnectPolicy();
        $this->clock = $clock ?? new MonotonicClock();
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function connect(): void
    {
        $this->resetSseState();
        $fiber = new \Fiber(fn () => $this->handleInitialize());
        $this->activeFiber = $fiber;

        $this->activeSuspend = $fiber->start();

        while (!$fiber->isTerminated()) {
            $this->tick();
        }

        $result = $fiber->getReturn();
        $this->activeFiber = null;
        $this->activeSuspend = null;
        $this->resetSseState();

        if ($result instanceof Error) {
            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('HTTP client connected and initialized', ['endpoint' => $this->endpoint]);

        if ($this->listen) {
            $this->closeListenStream();
            $this->openListenStream();
        }
    }

    public function onHeaders(callable $callback): void
    {
        $this->headerCallback = $callback;
    }

    /**
     * The session ID minted by the server for this connection, if any.
     *
     * Captured from the Mcp-Session-Id response header of a handshake-era
     * server; always null on 2026-07-28, which removed protocol-level sessions
     * (SEP-2567). Useful for logging: every connect() starts a new session
     * and close() ends it, so it cannot be reused on a later transport.
     *
     * @return string|null null until the server mints a session, and again after close() or a 404 session expiry
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function send(string $data): void
    {
        // An asynchronous PSR-18 client (amphp, through Symfony's AmpHttpClient
        // or its own adapter) suspends the fiber it is called from, for its event
        // loop to resume; only from {main} does it run that loop itself. Inside
        // the request fiber, the one thing that would resume it is this
        // transport, with an answer meant for the protocol. So the request fiber
        // steps out and the next tick sends from {main}, body reads included.
        if (null !== $this->activeFiber && \Fiber::getCurrent() === $this->activeFiber) {
            \Fiber::suspend(['type' => 'send', 'data' => $data]);

            return;
        }

        $request = $this->requestFactory->createRequest('POST', $this->endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream($data));

        foreach ($this->sessionHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Protocol-derived first, so an explicitly configured header still wins:
        // the caller passing one is making a deliberate choice about this
        // connection, and a proxy credential is the usual reason.
        foreach ($this->protocolHeaders($data) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $this->logger->debug('Sending HTTP request', ['data' => $data]);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            $this->handleError($e);
            throw new ConnectionException('HTTP request failed: '.$e->getMessage(), 0, $e);
        }

        // A 404 for a session-bound request requires a fresh initialization,
        // not a timeout that leaves the expired session reusable.
        if (404 === $response->getStatusCode() && $request->hasHeader('Mcp-Session-Id')) {
            $response->getBody()->close();
            $this->expireSession();

            throw new ConnectionException('The HTTP session has expired.', 404);
        }

        if ($response->hasHeader('Mcp-Session-Id')) {
            $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
            $this->logger->debug('Received session ID', ['session_id' => $this->sessionId]);
        }

        // A notification carries no id, so nobody is waiting for this body: it is
        // discarded rather than read as the answer to some other request.
        if (self::isNotification($data)) {
            $response->getBody()->close();

            return;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if ($response->getStatusCode() >= 400) {
            try {
                $body = $response->getBody()->getContents();
            } catch (\RuntimeException) {
                // The status alone still tells what went wrong.
                $body = '';
            }

            $this->handleErrorStatus($data, $response->getStatusCode(), $response->getReasonPhrase(), $body);

            return;
        }

        if (str_contains($contentType, 'text/event-stream')) {
            $this->startSseStream($this->responseStream($response->getBody()), self::requestIdOf($data));

            return;
        }

        try {
            $body = $response->getBody()->getContents();
        } catch (\RuntimeException $e) {
            $this->failUnanswered($data, $e->getMessage());

            return;
        }

        $dispatched = str_contains($contentType, 'application/json') && '' !== trim($body);
        if ($dispatched) {
            $this->handleMessage($body);
        }

        if (null !== ($requestId = self::requestIdOf($data)) && !($dispatched && self::answers($body, $requestId))) {
            $this->failUnanswered($data, '' === trim($body) ? 'empty body' : 'no answer in the body');
        }
    }

    /**
     * Send what the request fiber stepped out for, from {main}.
     *
     * A failure is thrown back into the fiber, so it surfaces from send() as it
     * would have had the message been sent in place.
     */
    private function processSends(): void
    {
        while (null !== ($fiber = $this->activeFiber) && $fiber->isSuspended() && 'send' === ($this->activeSuspend['type'] ?? null)) {
            /** @var SendSuspend $suspend */
            $suspend = $this->activeSuspend;

            try {
                $this->send($suspend['data']);
            } catch (\Throwable $e) {
                $this->activeSuspend = $fiber->throw($e);

                continue;
            }

            $this->activeSuspend = $fiber->resume();
        }
    }

    /**
     * Fails a request whose response ended without its answer at once, rather than at its timeout.
     */
    private function failUnanswered(string $sent, string $reason): void
    {
        $requestId = self::requestIdOf($sent);
        if (null === $requestId || null === $this->state) {
            return;
        }

        $this->logger->warning('Response ended without answering the request', ['request_id' => $requestId, 'reason' => $reason]);
        $this->state->storeResponse($requestId, Error::forInternalError('The response ended without answering the request: '.$reason, $requestId)->jsonSerialize());
    }

    /**
     * Whether the outgoing message is a notification: a `method` and no `id`, so
     * it asks the server for nothing and no answer of its own can exist.
     */
    private static function isNotification(string $data): bool
    {
        $payload = json_decode($data, true);

        return \is_array($payload) && \array_key_exists('method', $payload) && !\array_key_exists('id', $payload);
    }

    /**
     * Fails a request refused at the HTTP level at once, rather than at its timeout.
     */
    private function handleErrorStatus(string $sent, int $status, string $reason, string $body): void
    {
        $request = json_decode($sent, true);
        $requestId = \is_array($request) && \array_key_exists('method', $request) ? ($request['id'] ?? null) : null;
        $answer = '' === trim($body) ? null : json_decode($body, true);

        if (\is_array($answer) && null !== $requestId && ($answer['id'] ?? null) === $requestId && self::isWellFormedAnswer($answer)) {
            $this->handleMessage($body);

            return;
        }

        if ((!\is_string($requestId) && !\is_int($requestId)) || null === $this->state) {
            $this->logger->warning('Server refused a message', ['status' => $status, 'body' => $body]);

            return;
        }

        $error = \is_array($answer['error'] ?? null) && \is_int($answer['error']['code'] ?? null)
            ? new Error($requestId, $answer['error']['code'], \is_string($answer['error']['message'] ?? null) ? $answer['error']['message'] : $reason, $answer['error']['data'] ?? null)
            : Error::forInvalidRequest(\sprintf('Server answered with HTTP %d%s.', $status, '' !== $reason ? ' '.$reason : ''), $requestId);

        $this->state->storeResponse($requestId, $error->jsonSerialize());
    }

    /**
     * @param array<mixed> $answer
     */
    private static function isWellFormedAnswer(array $answer): bool
    {
        try {
            \array_key_exists('error', $answer) ? Error::fromArray($answer) : Response::fromArray($answer);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @param McpFiber                                                                $fiber
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        $this->activeFiber = $fiber;
        try {
            $this->activeSuspend = $fiber->start();
            while (!$fiber->isTerminated()) {
                $this->tick();
            }

            return $fiber->getReturn();
        } finally {
            $this->activeFiber = null;
            $this->activeSuspend = null;
            $this->activeStream?->close();
            $this->resetSseState();
        }
    }

    public function close(): void
    {
        if (null !== $this->sessionId) {
            try {
                $request = $this->requestFactory->createRequest('DELETE', $this->endpoint);

                foreach ([...$this->sessionHeaders(), ...$this->headers] as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                $this->httpClient->sendRequest($request);
                $this->logger->info('Session closed', ['session_id' => $this->sessionId]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to close session', ['exception' => $e]);
            }
        }

        $this->sessionId = null;
        $this->resetSseState();
        $this->closeListenStream();
        $this->handleClose('Transport closed');
    }

    /**
     * Forget a session the server no longer knows, so the next connect starts a new one.
     */
    private function expireSession(): void
    {
        $this->sessionId = null;
        // Cancellation notifications are best-effort: their exception can
        // be logged and swallowed, but the connection must stay invalid.
        $this->state?->setInitialized(false);
    }

    /**
     * Headers a handshake-era connection carries on every request after `initialize`.
     *
     * 2026-07-28 derives its version header from each message instead, see
     * {@see protocolHeaders()}.
     *
     * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#protocol-version-header
     *
     * @return array<string, string>
     */
    private function sessionHeaders(): array
    {
        $headers = [];

        if (null !== $this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        // Not on `initialize` itself, nor on the one that replaces an expired
        // session: the version is only settled by its answer.
        $version = $this->state?->getProtocolVersion();
        if (null !== $version && !$version->isModern() && $this->state?->isInitialized()) {
            $headers[McpHeader::PROTOCOL_VERSION] = $version->value;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function protocolHeaders(string $payload): array
    {
        if (!\is_callable($this->headerCallback)) {
            return [];
        }

        try {
            return ($this->headerCallback)($payload);
        } catch (\Throwable $e) {
            // Headers mirror the body; failing to derive them is a bug worth
            // reporting, but dropping the request would be a worse outcome than
            // sending it the way an earlier revision would have.
            $this->logger->error('Could not derive protocol headers', ['exception' => $e]);

            return [];
        }
    }

    /**
     * Open the standalone GET stream, if the server offers one.
     *
     * Failing to is not an error: the stream is optional on both sides, and
     * everything that belongs to a request still arrives on its response.
     */
    private function openListenStream(): void
    {
        $version = $this->state?->getProtocolVersion();
        if (null !== $version && $version->isModern()) {
            // 2026-07-28 has no standalone stream: a server asks for input
            // within the result of the request that needs it.
            return;
        }

        $request = $this->requestFactory->createRequest('GET', $this->endpoint)
            ->withHeader('Accept', 'text/event-stream');

        foreach ([...$this->sessionHeaders(), ...$this->headers] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not open the listening stream', ['exception' => $e]);

            return;
        }

        if (405 === $response->getStatusCode()) {
            $response->getBody()->close();
            $this->logger->info('Server offers no listening stream');

            return;
        }

        if (200 !== $response->getStatusCode() || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')) {
            $response->getBody()->close();
            $this->logger->warning('Server answered the listening stream with something else', [
                'status' => $response->getStatusCode(),
                'content_type' => $response->getHeaderLine('Content-Type'),
            ]);

            return;
        }

        $stream = $this->nonBlocking($response->getBody(), $switched);
        if (!$switched) {
            $stream->close();
            $this->logger->warning('Not listening: the HTTP client returns a response body that cannot be read without blocking');

            return;
        }

        $this->listenStream = $stream;
        $this->listenParser->reset();
        $this->logger->info('Listening for server messages', ['session_id' => $this->sessionId]);
    }

    private function closeListenStream(): void
    {
        $this->listenStream?->close();
        $this->listenStream = null;
        $this->listenParser->reset();
    }

    /**
     * The same body, made to return what it has instead of waiting for more.
     *
     * Always returns a stream to read the body with: the given one when it has
     * no PHP stream behind it to switch, as detaching it would leave nothing to
     * read with.
     *
     * @param-out bool $switched set to whether reads no longer block
     */
    private function nonBlocking(StreamInterface $body, ?bool &$switched = null): StreamInterface
    {
        $switched = false;

        // A body backed by a PHP stream lists the stream's metadata.
        if ([] === ($body->getMetadata() ?? [])) {
            return $body;
        }

        $resource = $body->detach();
        if (!\is_resource($resource)) {
            return $body;
        }

        $switched = stream_set_blocking($resource, false);

        return $this->streamFactory->createStreamFromResource($resource);
    }

    private function tick(): void
    {
        $this->processSends();
        $this->checkInterruption();
        $this->processSSEStream();
        $this->processListenStream();
        $this->checkInterruption();
        $this->processFiber();
        $this->processSseReconnect();

        usleep(1000); // 1ms
    }

    private function checkInterruption(): void
    {
        if (null === $this->activeSuspend || !$this->activeFiber?->isSuspended()) {
            return;
        }

        $error = null;
        if (($this->activeSuspend['cancellation'] ?? null)?->isCancellationRequested()) {
            $error = new RequestCancelledException('The client cancelled the request.');
        } elseif (null !== ($deadline = $this->activeSuspend['deadline'] ?? null) && microtime(true) >= $deadline) {
            $error = new TimeoutException('The request deadline expired.');
        }

        if (null !== $error) {
            // A blocking read may have delivered a response after cancellation.
            $this->state?->consumeResponse($this->activeSuspend['request_id']);
            // The fiber may step out again to send the cancellation notification.
            $this->activeSuspend = $this->activeFiber->throw($error);
        }
    }

    /**
     * Read SSE data incrementally from active stream.
     */
    private function processSSEStream(): void
    {
        $stream = $this->activeStream;
        if (null === $stream) {
            return;
        }

        try {
            $ended = $this->pumpSse($stream, $this->sseParser, $this->processSSEEvent(...), $this->abortSseStream(...));
        } catch (\RuntimeException $e) {
            $stream->close();
            $this->activeStream = null;
            $this->sseParser->reset();
            $this->handleError($e);
            $this->scheduleSseReconnect();

            return;
        }

        // A message listener may have closed the transport, or sent a message
        // whose response replaced this stream; an overflow aborted it.
        if (!$ended || $stream !== $this->activeStream) {
            return;
        }

        // The stream ended without a trailing blank line. What is left may
        // be a truncated event: dispatching it would adopt its ID, and the
        // resumed stream would then replay only what follows it. Dispatch
        // it only when it completes the request or nothing will be resumed.
        // Like the SSE standard, which discards an unterminated event, this
        // also drops a complete one that lacks the blank line, so a priming
        // event sent that way leaves no ID to resume from.
        $rest = $this->sseParser->rest();
        if (null !== $rest && (!$this->isSseStreamExpected() || $this->isResponseForCurrentSseRequest($rest['data']))) {
            $this->processSSEEvent($rest);
        }

        $stream->close();
        $this->activeStream = null;

        if ($this->sseRequestCompleted) {
            $this->nextReconnectAtMs = null;
        } else {
            $this->scheduleSseReconnect();
        }
    }

    /**
     * Read what arrived on the listening stream, if one is open.
     *
     * Its end is no error: the server may close it at any time, and every
     * response still arrives on its own request.
     */
    private function processListenStream(): void
    {
        if (null === $this->listenStream) {
            return;
        }

        $done = $this->pumpSse($this->listenStream, $this->listenParser, $this->dispatchSseData(...), function (string $reason): void {
            $this->logger->warning('Closing the listening stream: '.$reason, ['session_id' => $this->sessionId]);
        });

        if ($done) {
            if (null !== $rest = $this->listenParser->rest()) {
                $this->dispatchSseData($rest);
            }

            $this->closeListenStream();
            $this->logger->info('Listening stream ended', ['session_id' => $this->sessionId]);
        }
    }

    /**
     * Read a chunk of an SSE stream and dispatch every event it completes.
     *
     * @param callable(SseEvent): void       $onEvent
     * @param callable(string $reason): void $onOverflow called when the buffer would exceed its cap
     *
     * @return bool whether the stream is done: ended, or given up for its size
     */
    private function pumpSse(StreamInterface $stream, SseParser $parser, callable $onEvent, callable $onOverflow): bool
    {
        $chunk = $stream->eof() ? '' : $stream->read(4096);

        if (!$parser->append($chunk)) {
            $onOverflow(\sprintf('buffered %d bytes without a complete event, exceeding the %d byte limit', $parser->bufferedBytes() + \strlen($chunk), $parser->maxBufferBytes));
            $parser->reset();

            return true;
        }

        while (null !== ($event = $parser->next())) {
            $onEvent($event);
        }

        return $stream->eof();
    }

    /**
     * Resume an interrupted response stream once its delay has elapsed.
     *
     * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#resumability-and-redelivery
     */
    private function processSseReconnect(): void
    {
        if (null === $this->nextReconnectAtMs) {
            return;
        }

        if (!$this->isSseStreamExpected()) {
            $this->nextReconnectAtMs = null;

            return;
        }

        if ($this->nowMs() < $this->nextReconnectAtMs) {
            return;
        }

        $this->nextReconnectAtMs = null;
        ++$this->reconnectAttempts;

        $request = $this->requestFactory->createRequest('GET', $this->endpoint)
            ->withHeader('Accept', 'text/event-stream')
            ->withHeader('Last-Event-ID', (string) $this->lastEventId);

        foreach ([...$this->sessionHeaders(), ...$this->headers] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $this->logger->info('Reconnecting SSE stream', [
            'attempt' => $this->reconnectAttempts,
            'max_attempts' => $this->reconnectPolicy->maxAttempts,
            'last_event_id' => $this->lastEventId,
            'session_id' => $this->sessionId,
        ]);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            $this->handleError($e);
            $this->scheduleSseReconnect();

            return;
        }

        $status = $response->getStatusCode();

        if (404 === $status && $request->hasHeader('Mcp-Session-Id')) {
            $response->getBody()->close();
            $this->expireSession();
            $this->failSseStream('SSE stream ended before the request completed and the HTTP session has expired');

            return;
        }

        // A client error will not resolve itself: 405 means the server offers no
        // GET stream. Nor will a redirect the HTTP client did not follow. Only a
        // timeout or a rate limit is worth waiting out.
        if ($status >= 300 && $status < 500 && 408 !== $status && 429 !== $status) {
            $response->getBody()->close();
            $this->failSseStream(\sprintf('SSE stream ended before the request completed and the server rejected resuming it with HTTP %d', $status));

            return;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ($status >= 400 || !str_contains($contentType, 'text/event-stream')) {
            $response->getBody()->close();
            $this->logger->warning('SSE reconnect did not return an event stream', [
                'attempt' => $this->reconnectAttempts,
                'status' => $status,
                'content_type' => $contentType,
            ]);
            $this->scheduleSseReconnect();

            return;
        }

        $this->activeStream = $this->responseStream($response->getBody());
        $this->sseParser->reset();
    }

    /**
     * Arrange the next GET resumption without blocking request timeout handling.
     */
    private function scheduleSseReconnect(): void
    {
        if (!$this->isSseStreamExpected()) {
            $this->nextReconnectAtMs = null;

            return;
        }

        // No version is settled yet while probing with `server/discover`, so
        // the server's era is unknown. A 2026-07-28 server sends no event IDs
        // to resume from, and one that sends some anyway answers the GET with
        // 405, which fails the request at once.
        $version = $this->state?->getProtocolVersion();
        if (null !== $version && !$version->supportsSseResumption()) {
            $this->failSseStream(\sprintf('SSE stream ended before the request completed and protocol version %s does not resume streams', $version->value));

            return;
        }

        if (null === $this->lastEventId || '' === $this->lastEventId) {
            $this->failSseStream('SSE stream ended before the request completed and did not provide an event ID for resumption');

            return;
        }

        if ($this->reconnectAttempts >= $this->reconnectPolicy->maxAttempts) {
            $this->failSseStream(\sprintf('SSE stream ended before the request completed and the maximum of %d reconnect attempts was reached', $this->reconnectPolicy->maxAttempts));

            return;
        }

        $delayMs = $this->reconnectPolicy->delayMs($this->reconnectAttempts, $this->serverRetryMs);
        $this->nextReconnectAtMs = $this->nowMs() + $delayMs;

        $this->logger->info('SSE stream disconnected; scheduling reconnect', [
            'attempt' => $this->reconnectAttempts + 1,
            'max_attempts' => $this->reconnectPolicy->maxAttempts,
            'delay_ms' => $delayMs,
            'last_event_id' => $this->lastEventId,
            'session_id' => $this->sessionId,
        ]);
    }

    private function nowMs(): float
    {
        $now = $this->clock->now();

        return (int) $now->format('U') * 1000 + (int) $now->format('u') / 1000;
    }

    private function isSseStreamExpected(): bool
    {
        // Without a request ID (a batch, or a response the server answered
        // with a stream) nothing tells when the stream is done.
        if ($this->sseRequestCompleted || null === $this->sseRequestId || null === $this->state) {
            return false;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            if ($pending['request_id'] === $this->sseRequestId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Give up on the current response stream and fail the request it belongs to.
     */
    private function failSseStream(string $reason): void
    {
        $this->activeStream?->close();
        $this->activeStream = null;
        $this->sseParser->reset();
        $this->nextReconnectAtMs = null;

        $this->logger->warning($reason, [
            'attempts' => $this->reconnectAttempts,
            'last_event_id' => $this->lastEventId,
            'session_id' => $this->sessionId,
        ]);

        $requestId = $this->sseRequestId;
        if (null === $requestId || !$this->isSseStreamExpected()) {
            return;
        }

        $this->state?->storeResponse($requestId, Error::forInternalError($reason, $requestId)->jsonSerialize());
    }

    /**
     * Tear down the active SSE stream and fail any in-flight request.
     *
     * The waiting fiber is resolved with an error immediately so the caller
     * fails fast, rather than spinning until the request timeout elapses.
     */
    private function abortSseStream(string $reason): void
    {
        $bufferedBytes = $this->sseParser->bufferedBytes();
        $this->sseParser->reset();
        $this->activeStream?->close();
        $this->activeStream = null;
        $this->nextReconnectAtMs = null;

        $this->logger->warning('Aborting SSE stream: '.$reason, [
            'session_id' => $this->sessionId,
            'buffered_bytes' => $bufferedBytes,
            'max_sse_buffer_bytes' => $this->sseParser->maxBufferBytes,
        ]);

        if (null === $this->state) {
            return;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            $requestId = $pending['request_id'];
            $error = Error::forInternalError('SSE stream aborted: '.$reason, $requestId);
            $this->state->storeResponse($requestId, $error->jsonSerialize());
        }
    }

    /**
     * Handle an event of the response stream, tracking what a resumption needs.
     *
     * @param SseEvent $event
     */
    private function processSSEEvent(array $event): void
    {
        // An event that carries data or advances the event ID proves the stream
        // made progress, so a later polling close starts a fresh retry sequence.
        // Replaying the same empty event does not, or a server doing so on every
        // GET would be reconnected to without bound.
        if ('' !== $event['data'] || (null !== $event['id'] && $event['id'] !== $this->lastEventId)) {
            $this->reconnectAttempts = 0;
        }

        if (null !== $event['id']) {
            $this->lastEventId = $event['id'];
        }

        if (null !== $event['retry']) {
            $this->serverRetryMs = $event['retry'];
        }

        if ($this->isResponseForCurrentSseRequest($event['data'])) {
            $this->sseRequestCompleted = true;
        }

        $this->dispatchSseData($event);
    }

    /**
     * @param SseEvent $event
     */
    private function dispatchSseData(array $event): void
    {
        if ('' !== $event['data']) {
            $this->handleMessage($event['data']);
        }
    }

    private function isResponseForCurrentSseRequest(string $data): bool
    {
        return null !== $this->sseRequestId && self::answers($data, $this->sseRequestId);
    }

    private static function answers(string $data, int|string $requestId): bool
    {
        $decoded = json_decode($data, true);
        if (!\is_array($decoded)) {
            return false;
        }

        foreach (array_is_list($decoded) ? $decoded : [$decoded] as $message) {
            // One the protocol cannot read is dropped there, so the request is still waiting.
            if (\is_array($message) && !isset($message['method']) && ($message['id'] ?? null) === $requestId
                && self::isWellFormedAnswer($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body of a response stream, readable alongside the listening stream.
     *
     * While listening, a request on the GET stream can be what this response
     * waits for, so neither stream may block the other.
     */
    private function responseStream(StreamInterface $body): StreamInterface
    {
        return null !== $this->listenStream ? $this->nonBlocking($body) : $body;
    }

    private function startSseStream(StreamInterface $stream, int|string|null $requestId): void
    {
        $this->resetSseState();
        $this->activeStream = $stream;
        $this->sseRequestId = $requestId;
    }

    private function resetSseState(): void
    {
        $this->activeStream = null;
        $this->sseParser->reset();
        $this->sseRequestId = null;
        $this->sseRequestCompleted = false;
        $this->lastEventId = null;
        $this->serverRetryMs = null;
        $this->reconnectAttempts = 0;
        $this->nextReconnectAtMs = null;
    }

    /**
     * The ID of an outgoing request, or null for a notification, a response or a batch.
     */
    private static function requestIdOf(string $data): int|string|null
    {
        $payload = json_decode($data, true);
        if (!\is_array($payload) || !\is_string($payload['method'] ?? null)) {
            return null;
        }

        $id = $payload['id'] ?? null;

        return \is_int($id) || \is_string($id) ? $id : null;
    }

    private function processFiber(): void
    {
        if (null === $this->activeFiber || !$this->activeFiber->isSuspended()) {
            return;
        }

        // Waiting to have a message sent, which the next tick does.
        if ('send' === ($this->activeSuspend['type'] ?? null)) {
            return;
        }

        if (null === $this->state) {
            return;
        }

        $pendingRequests = $this->state->getPendingRequests();

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'];

            $response = $this->state->consumeResponse($requestId);

            if (null !== $response) {
                $this->logger->debug('Resuming fiber with response', ['request_id' => $requestId]);
                $this->activeSuspend = $this->activeFiber->resume($response);

                return;
            }

            // The explicit per-call deadline replaces the default request timeout.
            if (null === ($this->activeSuspend['deadline'] ?? null) && microtime(true) - $timestamp >= $timeout) {
                $this->logger->warning('Request timed out', ['request_id' => $requestId]);
                $error = Error::forInternalError('Request timed out', $requestId);
                $this->activeSuspend = $this->activeFiber->resume($error);

                return;
            }
        }
    }
}
