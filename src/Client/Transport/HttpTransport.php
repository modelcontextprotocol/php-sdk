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

    /** @var (callable(float, ?float, ?string): void)|null */
    private $activeProgressCallback;

    /** @var StreamInterface|null Active SSE stream being read */
    private ?StreamInterface $activeStream = null;

    /** @var string Buffer for incomplete SSE data */
    private string $sseBuffer = '';

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

    /** Monotonic timestamp, in milliseconds, when the next resumption may start. */
    private ?float $nextReconnectAtMs = null;

    /** Protocol version copied onto transport-internal GET requests when available. */
    private ?string $protocolVersionHeader = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * Default cap on the bytes buffered while waiting for a complete SSE event.
     */
    public const DEFAULT_MAX_SSE_BUFFER_BYTES = 8 * 1024 * 1024;

    public const DEFAULT_MAX_RECONNECT_ATTEMPTS = 5;

    public const DEFAULT_INITIAL_RECONNECT_DELAY_MS = 1000;

    public const DEFAULT_MAX_RECONNECT_DELAY_MS = 10000;

    private readonly int $maxSseBufferBytes;

    private readonly int $maxReconnectAttempts;

    private readonly int $initialReconnectDelayMs;

    private readonly int $maxReconnectDelayMs;

    /**
     * @param string                       $endpoint                The MCP server endpoint URL
     * @param array<string, string>        $headers                 Additional headers to send
     * @param ClientInterface|null         $httpClient              PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory          PSR-17 request factory (auto-discovered if null)
     * @param StreamFactoryInterface|null  $streamFactory           PSR-17 stream factory (auto-discovered if null)
     * @param int                          $maxSseBufferBytes       Maximum bytes buffered while waiting for a complete
     *                                                              SSE event. A server that never sends the "\n\n" event
     *                                                              delimiter would otherwise grow the buffer without bound
     *                                                              and exhaust client memory; reaching the cap aborts the
     *                                                              stream instead. Raise it for servers that legitimately
     *                                                              emit single events larger than the default.
     * @param int                          $maxReconnectAttempts    Maximum GET attempts used to resume an interrupted SSE
     *                                                              stream. Zero disables resumption.
     * @param int                          $initialReconnectDelayMs Initial reconnect delay when the server has not sent
     *                                                              an SSE `retry` field. It doubles after each failed attempt.
     * @param int                          $maxReconnectDelayMs     Maximum client-selected reconnect delay. A server-provided
     *                                                              `retry` value is not capped.
     * @param (callable(): float)|null     $clock                   monotonic millisecond clock; primarily useful for tests
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxSseBufferBytes = self::DEFAULT_MAX_SSE_BUFFER_BYTES,
        int $maxReconnectAttempts = self::DEFAULT_MAX_RECONNECT_ATTEMPTS,
        int $initialReconnectDelayMs = self::DEFAULT_INITIAL_RECONNECT_DELAY_MS,
        int $maxReconnectDelayMs = self::DEFAULT_MAX_RECONNECT_DELAY_MS,
        ?callable $clock = null,
    ) {
        parent::__construct($logger);

        if ($maxSseBufferBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum SSE buffer size must be a positive number of bytes, got %d.', $maxSseBufferBytes));
        }

        if ($maxReconnectAttempts < 0) {
            throw new InvalidArgumentException(\sprintf('The maximum number of SSE reconnect attempts must be zero or greater, got %d.', $maxReconnectAttempts));
        }

        if ($initialReconnectDelayMs < 0) {
            throw new InvalidArgumentException(\sprintf('The initial SSE reconnect delay must be zero or greater, got %d milliseconds.', $initialReconnectDelayMs));
        }

        if ($maxReconnectDelayMs < $initialReconnectDelayMs) {
            throw new InvalidArgumentException(\sprintf('The maximum SSE reconnect delay must be at least the initial delay of %d milliseconds, got %d.', $initialReconnectDelayMs, $maxReconnectDelayMs));
        }

        $this->maxSseBufferBytes = $maxSseBufferBytes;
        $this->maxReconnectAttempts = $maxReconnectAttempts;
        $this->initialReconnectDelayMs = $initialReconnectDelayMs;
        $this->maxReconnectDelayMs = $maxReconnectDelayMs;
        $this->clock = null === $clock
            ? static fn (): float => hrtime(true) / 1_000_000
            : \Closure::fromCallable($clock);
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function connect(): void
    {
        $this->resetSseState();
        $this->activeFiber = new \Fiber(fn () => $this->handleInitialize());

        $this->activeFiber->start();

        while (!$this->activeFiber->isTerminated()) {
            $this->tick();
        }

        $result = $this->activeFiber->getReturn();
        $this->activeFiber = null;
        $this->resetSseState();

        if ($result instanceof Error) {
            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('HTTP client connected and initialized', ['endpoint' => $this->endpoint]);
    }

    public function onHeaders(callable $callback): void
    {
        $this->headerCallback = $callback;
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

        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version');
        if ('' !== $protocolVersion) {
            $this->protocolVersionHeader = $protocolVersion;
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

        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            $this->startSseStream($response->getBody(), $this->requestIdFrom($data));
        } elseif (str_contains($contentType, 'application/json')) {
            $body = $response->getBody()->getContents();
            if (!empty($body)) {
                $this->handleMessage($body);
            }
        }
    }

    /**
     * @param McpFiber                                                                $fiber
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        $this->activeFiber = $fiber;
        $this->activeProgressCallback = $onProgress;
        $fiber->start();

        while (!$fiber->isTerminated()) {
            $this->tick();
        }

        $this->activeFiber = null;
        $this->activeProgressCallback = null;
        $this->resetSseState();

        return $fiber->getReturn();
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
        $this->protocolVersionHeader = null;
        $this->resetSseState();
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

    private function tick(): void
    {
        $this->processSSEStream();
        $this->processProgress();
        $this->processFiber();
        $this->processSseReconnect();

        usleep(1000); // 1ms
    }

    /**
     * Read SSE data incrementally from active stream.
     */
    private function processSSEStream(): void
    {
        if (null === $this->activeStream) {
            return;
        }

        try {
            if (!$this->activeStream->eof()) {
                $chunk = $this->activeStream->read(4096);
                if ('' !== $chunk) {
                    if (\strlen($this->sseBuffer) + \strlen($chunk) > $this->maxSseBufferBytes) {
                        $this->abortSseStream(\sprintf('buffered %d bytes without a complete event, exceeding the %d byte limit', \strlen($this->sseBuffer) + \strlen($chunk), $this->maxSseBufferBytes));

                        return;
                    }

                    $this->sseBuffer .= $chunk;
                }
            }
        } catch (\Throwable $e) {
            $this->handleSseStreamInterruption($e);

            return;
        }

        while (null !== ($event = $this->extractSSEEvent())) {
            if (!empty(trim($event))) {
                $this->processSSEEvent($event);
            }
        }

        try {
            $streamEnded = $this->activeStream->eof();
        } catch (\Throwable $e) {
            $this->handleSseStreamInterruption($e);

            return;
        }

        if ($streamEnded) {
            // The stream ended without a trailing blank line: dispatch what is left.
            if (!empty(trim($this->sseBuffer))) {
                $this->processSSEEvent($this->sseBuffer);
            }

            $this->sseBuffer = '';
            $this->activeStream = null;

            if (!$this->sseRequestCompleted) {
                $this->scheduleSseReconnect();
            } else {
                $this->nextReconnectAtMs = null;
            }
        }
    }

    private function handleSseStreamInterruption(\Throwable $error): void
    {
        $this->activeStream = null;
        $this->sseBuffer = '';
        $this->handleError($error);
        $this->scheduleSseReconnect();
    }

    /**
     * Resume an interrupted logical SSE stream once its delay has elapsed.
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

        if ($this->nowMilliseconds() < $this->nextReconnectAtMs) {
            return;
        }

        $this->nextReconnectAtMs = null;
        ++$this->reconnectAttempts;

        $request = $this->requestFactory->createRequest('GET', $this->endpoint)
            ->withHeader('Accept', 'text/event-stream');

        if (null !== $this->sessionId) {
            $request = $request->withHeader('Mcp-Session-Id', $this->sessionId);
        }

        if (null !== $this->lastEventId && '' !== $this->lastEventId) {
            $request = $request->withHeader('Last-Event-ID', $this->lastEventId);
        }

        if (null !== $this->protocolVersionHeader) {
            $request = $request->withHeader('MCP-Protocol-Version', $this->protocolVersionHeader);
        }

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $this->logger->info('Reconnecting SSE stream', [
            'attempt' => $this->reconnectAttempts,
            'max_attempts' => $this->maxReconnectAttempts,
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

        if ($response->hasHeader('Mcp-Session-Id')) {
            $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'text/event-stream')) {
            $this->logger->warning('SSE reconnect did not return an event stream', [
                'attempt' => $this->reconnectAttempts,
                'status' => $response->getStatusCode(),
                'content_type' => $contentType,
            ]);
            $this->scheduleSseReconnect();

            return;
        }

        $this->activeStream = $response->getBody();
        $this->sseBuffer = '';
        $this->sseRequestCompleted = false;
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

        if (null === $this->lastEventId || '' === $this->lastEventId) {
            $this->failSseStream('SSE stream ended before the request completed and did not provide an event ID for resumption');

            return;
        }

        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            $this->failSseStream(\sprintf('SSE stream ended before the request completed and the maximum of %d reconnect attempts was reached', $this->maxReconnectAttempts));

            return;
        }

        $delayMs = $this->serverRetryMs ?? $this->backoffDelayMs();
        $this->nextReconnectAtMs = $this->nowMilliseconds() + $delayMs;

        $this->logger->info('SSE stream disconnected; scheduling reconnect', [
            'attempt' => $this->reconnectAttempts + 1,
            'max_attempts' => $this->maxReconnectAttempts,
            'delay_ms' => $delayMs,
            'last_event_id' => $this->lastEventId,
            'session_id' => $this->sessionId,
        ]);
    }

    private function isSseStreamExpected(): bool
    {
        if ($this->sseRequestCompleted) {
            return false;
        }

        if (null === $this->sseRequestId) {
            return null !== $this->sessionId;
        }

        if (null === $this->state) {
            return false;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            if ($pending['request_id'] === $this->sseRequestId) {
                return true;
            }
        }

        return false;
    }

    private function backoffDelayMs(): int
    {
        $delay = $this->initialReconnectDelayMs;

        for ($attempt = 0; $attempt < $this->reconnectAttempts && $delay < $this->maxReconnectDelayMs; ++$attempt) {
            $delay = $delay > intdiv($this->maxReconnectDelayMs, 2)
                ? $this->maxReconnectDelayMs
                : $delay * 2;
        }

        return $delay;
    }

    private function failSseStream(string $reason): void
    {
        $this->activeStream = null;
        $this->sseBuffer = '';
        $this->nextReconnectAtMs = null;

        $this->logger->warning($reason, [
            'attempts' => $this->reconnectAttempts,
            'last_event_id' => $this->lastEventId,
            'session_id' => $this->sessionId,
        ]);

        if (null === $this->state || null === $this->sseRequestId) {
            return;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            if ($pending['request_id'] !== $this->sseRequestId) {
                continue;
            }

            $error = Error::forInternalError($reason, $this->sseRequestId);
            $this->state->storeResponse($this->sseRequestId, $error->jsonSerialize());

            return;
        }
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
        $this->nextReconnectAtMs = null;

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
    private function extractSSEEvent(): ?string
    {
        $position = null;
        $length = 0;

        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $delimiter) {
            $found = strpos($this->sseBuffer, $delimiter);

            if (false !== $found && (null === $position || $found < $position)) {
                $position = $found;
                $length = \strlen($delimiter);
            }
        }

        if (null === $position) {
            return null;
        }

        $event = substr($this->sseBuffer, 0, $position);
        $this->sseBuffer = substr($this->sseBuffer, $position + $length);

        return $event;
    }

    /**
     * Parse a single SSE event and handle the message.
     */
    private function processSSEEvent(string $event): void
    {
        // Receiving an event proves that the GET reopened the stream. A later
        // polling close starts a fresh retry sequence; only consecutive
        // failures to reopen the stream consume the attempt cap.
        $this->reconnectAttempts = 0;
        $dataLines = [];

        foreach (preg_split("/\r\n|\r|\n/", $event) ?: [] as $line) {
            if ('' === $line || str_starts_with($line, ':')) {
                continue;
            }

            $separator = strpos($line, ':');
            if (false === $separator) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $separator);
                $value = substr($line, $separator + 1);

                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            }

            if ('data' === $field) {
                $dataLines[] = $value;
            } elseif ('id' === $field && !str_contains($value, "\0")) {
                $this->lastEventId = $value;
            } elseif ('retry' === $field && null !== ($retryMs = $this->retryMilliseconds($value))) {
                $this->serverRetryMs = $retryMs;
            }
        }

        if ([] === $dataLines) {
            return;
        }

        $data = implode("\n", $dataLines);
        if ('' === $data) {
            return;
        }

        if ($this->isResponseForCurrentSseRequest($data)) {
            $this->sseRequestCompleted = true;
        }

        $this->handleMessage($data);
    }

    private function retryMilliseconds(string $value): ?int
    {
        if (1 !== preg_match('/^[0-9]+$/D', $value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ('' === $normalized) {
            return 0;
        }

        $maximum = (string) \PHP_INT_MAX;
        if (\strlen($normalized) > \strlen($maximum) || (\strlen($normalized) === \strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    private function isResponseForCurrentSseRequest(string $data): bool
    {
        if (null === $this->sseRequestId) {
            return false;
        }

        try {
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!\is_array($decoded)) {
            return false;
        }

        $messages = array_is_list($decoded) ? $decoded : [$decoded];

        foreach ($messages as $message) {
            if (!\is_array($message) || isset($message['method'])) {
                continue;
            }

            if (($message['id'] ?? null) === $this->sseRequestId && (\array_key_exists('result', $message) || \array_key_exists('error', $message))) {
                return true;
            }
        }

        return false;
    }

    private function startSseStream(StreamInterface $stream, int|string|null $requestId): void
    {
        $this->activeStream = $stream;
        $this->sseBuffer = '';
        $this->sseRequestId = $requestId;
        $this->sseRequestCompleted = false;
        $this->lastEventId = null;
        $this->serverRetryMs = null;
        $this->reconnectAttempts = 0;
        $this->nextReconnectAtMs = null;
    }

    private function resetSseState(): void
    {
        $this->activeStream = null;
        $this->sseBuffer = '';
        $this->sseRequestId = null;
        $this->sseRequestCompleted = false;
        $this->lastEventId = null;
        $this->serverRetryMs = null;
        $this->reconnectAttempts = 0;
        $this->nextReconnectAtMs = null;
    }

    private function requestIdFrom(string $payload): int|string|null
    {
        try {
            $decoded = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $requestId = $decoded['id'] ?? null;

        return \is_int($requestId) || \is_string($requestId) ? $requestId : null;
    }

    private function nowMilliseconds(): float
    {
        return (float) ($this->clock)();
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
                $this->activeFiber->resume($response);

                return;
            }

            if (time() - $timestamp >= $timeout) {
                $this->logger->warning('Request timed out', ['request_id' => $requestId]);
                $error = Error::forInternalError('Request timed out', $requestId);
                $this->activeFiber->resume($error);

                return;
            }
        }
    }
}
