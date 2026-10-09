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
use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
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

    /** @var FiberSuspend|null */
    private ?array $activeSuspend = null;

    /** @var (callable(float, ?float, ?string): void)|null */
    private $activeProgressCallback;

    /** @var StreamInterface|null Active SSE stream being read */
    private ?StreamInterface $activeStream = null;

    /** @var string Buffer for incomplete SSE data */
    private string $sseBuffer = '';

    /** @var StreamInterface|null The standalone GET stream the server may send on unprompted */
    private ?StreamInterface $listenStream = null;

    /** @var string Buffer for incomplete SSE data on the listening stream */
    private string $listenBuffer = '';

    /**
     * Default cap on the bytes buffered while waiting for a complete SSE event.
     */
    public const DEFAULT_MAX_SSE_BUFFER_BYTES = 8 * 1024 * 1024;

    private readonly int $maxSseBufferBytes;

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
    ) {
        parent::__construct($logger);

        if ($maxSseBufferBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum SSE buffer size must be a positive number of bytes, got %d.', $maxSseBufferBytes));
        }

        $this->maxSseBufferBytes = $maxSseBufferBytes;
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function connect(): void
    {
        $fiber = new \Fiber(fn () => $this->handleInitialize());
        $this->activeFiber = $fiber;

        $fiber->start();

        while (!$fiber->isTerminated()) {
            $this->tick();
        }

        $result = $fiber->getReturn();
        $this->activeFiber = null;

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
     * (SEP-2567). A caller that cannot keep the transport alive between
     * interactions can persist it and pass it back through the constructor's
     * $headers on a later transport, where it wins over the tracked value.
     *
     * @return string|null null until the server mints a session, and again after close()
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function send(string $data): void
    {
        $request = $this->requestFactory->createRequest('POST', $this->endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream($data));

        if (null !== $this->sessionId) {
            $request = $request->withHeader('Mcp-Session-Id', $this->sessionId);
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
            $this->handleErrorStatus($data, $response->getStatusCode(), $response->getReasonPhrase(), $response->getBody()->getContents());

            return;
        }

        if (str_contains($contentType, 'text/event-stream')) {
            // While listening, a request on the GET stream can be what this
            // response waits for, so neither stream may block the other.
            $this->activeStream = null !== $this->listenStream
                ? $this->nonBlocking($response->getBody())
                : $response->getBody();
            $this->sseBuffer = '';
        } elseif (str_contains($contentType, 'application/json')) {
            $body = $response->getBody()->getContents();
            if (!empty($body)) {
                $this->handleMessage($body);
            }
        }
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
        $this->activeProgressCallback = $onProgress;
        try {
            $this->activeSuspend = $fiber->start();
            while (!$fiber->isTerminated()) {
                $this->tick();
            }

            return $fiber->getReturn();
        } finally {
            $this->activeFiber = null;
            $this->activeSuspend = null;
            $this->activeProgressCallback = null;
            $this->activeStream?->close();
            $this->activeStream = null;
            $this->sseBuffer = '';
        }
    }

    public function close(): void
    {
        if (null !== $this->sessionId) {
            try {
                $request = $this->requestFactory->createRequest('DELETE', $this->endpoint)
                    ->withHeader('Mcp-Session-Id', $this->sessionId);

                foreach ($this->headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                $this->httpClient->sendRequest($request);
                $this->logger->info('Session closed', ['session_id' => $this->sessionId]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to close session', ['exception' => $e]);
            }
        }

        $this->sessionId = null;
        $this->activeStream = null;
        $this->closeListenStream();
        $this->handleClose('Transport closed');
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

        if (null !== $this->sessionId) {
            $request = $request->withHeader('Mcp-Session-Id', $this->sessionId);
        }
        if (null !== $version) {
            $request = $request->withHeader('MCP-Protocol-Version', $version->value);
        }

        foreach ($this->headers as $name => $value) {
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
        $this->listenBuffer = '';
        $this->logger->info('Listening for server messages', ['session_id' => $this->sessionId]);
    }

    private function closeListenStream(): void
    {
        $this->listenStream?->close();
        $this->listenStream = null;
        $this->listenBuffer = '';
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
        $this->checkInterruption();
        $this->processSSEStream();
        $this->processListenStream();
        $this->processProgress();
        $this->checkInterruption();
        $this->processFiber();

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
            $this->activeFiber->throw($error);
        }
    }

    /**
     * Read SSE data incrementally from active stream.
     */
    private function processSSEStream(): void
    {
        if (null === $this->activeStream) {
            return;
        }

        $done = $this->pumpSse($this->activeStream, $this->sseBuffer, $this->abortSseStream(...));

        if ($done) {
            $this->sseBuffer = '';
            $this->activeStream = null;
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

        $done = $this->pumpSse($this->listenStream, $this->listenBuffer, function (string $reason): void {
            $this->logger->warning('Closing the listening stream: '.$reason, ['session_id' => $this->sessionId]);
        });

        if ($done) {
            $this->closeListenStream();
            $this->logger->info('Listening stream ended', ['session_id' => $this->sessionId]);
        }
    }

    /**
     * Read a chunk of an SSE stream and dispatch every event it completes.
     *
     * @param callable(string $reason): void $onOverflow called when the buffer would exceed its cap
     *
     * @return bool whether the stream is done: ended, or given up for its size
     */
    private function pumpSse(StreamInterface $stream, string &$buffer, callable $onOverflow): bool
    {
        if (!$stream->eof()) {
            $chunk = $stream->read(4096);
            if ('' !== $chunk) {
                if (\strlen($buffer) + \strlen($chunk) > $this->maxSseBufferBytes) {
                    $onOverflow(\sprintf('buffered %d bytes without a complete event, exceeding the %d byte limit', \strlen($buffer) + \strlen($chunk), $this->maxSseBufferBytes));

                    return true;
                }

                $buffer .= $chunk;
            }
        }

        while (null !== ($event = $this->extractSSEEvent($buffer))) {
            if (!empty(trim($event))) {
                $this->processSSEEvent($event);
            }
        }

        if ($stream->eof()) {
            // The stream ended without a trailing blank line: dispatch what is left.
            if (!empty(trim($buffer))) {
                $this->processSSEEvent($buffer);
            }

            return true;
        }

        return false;
    }

    /**
     * Tear down the active SSE stream and fail any in-flight request.
     *
     * The waiting fiber is resolved with an error immediately so the caller
     * fails fast, rather than spinning until the request timeout elapses.
     */
    private function abortSseStream(string $reason): void
    {
        $bufferedBytes = \strlen($this->sseBuffer);
        $this->sseBuffer = '';
        $this->activeStream = null;

        $this->logger->warning('Aborting SSE stream: '.$reason, [
            'session_id' => $this->sessionId,
            'buffered_bytes' => $bufferedBytes,
            'max_sse_buffer_bytes' => $this->maxSseBufferBytes,
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
     * Take the next complete event off the buffer, or null if none is complete yet.
     *
     * Per the SSE specification, lines are terminated by CRLF, LF or CR, so an
     * event is delimited by any pair of those. Servers built on sse-starlette
     * (the MCP Python SDK) use CRLF.
     */
    private function extractSSEEvent(string &$buffer): ?string
    {
        $position = null;
        $length = 0;

        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $delimiter) {
            $found = strpos($buffer, $delimiter);

            if (false !== $found && (null === $position || $found < $position)) {
                $position = $found;
                $length = \strlen($delimiter);
            }
        }

        if (null === $position) {
            return null;
        }

        $event = substr($buffer, 0, $position);
        $buffer = substr($buffer, $position + $length);

        return $event;
    }

    /**
     * Parse a single SSE event and handle the message.
     */
    private function processSSEEvent(string $event): void
    {
        $data = '';

        foreach (preg_split("/\r\n|\r|\n/", $event) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= trim(substr($line, 5));
            }
        }

        if (!empty($data)) {
            $this->handleMessage($data);
            // Now, so progress keeps its order among notifications.
            $this->processProgress();
        }
    }

    /**
     * Process pending progress updates from session and execute callback.
     */
    private function processProgress(): void
    {
        if (null === $this->activeProgressCallback || null === $this->state) {
            return;
        }

        $updates = $this->state->consumeProgressUpdates();

        foreach ($updates as $update) {
            try {
                ($this->activeProgressCallback)(
                    $update['progress'],
                    $update['total'],
                    $update['message'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Progress callback failed', ['exception' => $e]);
            }
        }
    }

    private function processFiber(): void
    {
        if (null === $this->activeFiber || !$this->activeFiber->isSuspended()) {
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
            if (null === ($this->activeSuspend['deadline'] ?? null) && time() - $timestamp >= $timeout) {
                $this->logger->warning('Request timed out', ['request_id' => $requestId]);
                $error = Error::forInternalError('Request timed out', $requestId);
                $this->activeSuspend = $this->activeFiber->resume($error);

                return;
            }
        }
    }
}
