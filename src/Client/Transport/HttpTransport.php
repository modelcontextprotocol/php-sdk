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
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxSseBufferBytes = self::DEFAULT_MAX_SSE_BUFFER_BYTES,
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
        $this->activeFiber = new \Fiber(fn () => $this->handleInitialize());

        $this->activeFiber->start();

        while (!$this->activeFiber->isTerminated()) {
            $this->tick();
        }

        $result = $this->activeFiber->getReturn();
        $this->activeFiber = null;

        if ($result instanceof Error) {
            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('HTTP client connected and initialized', ['endpoint' => $this->endpoint]);
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

        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            $this->activeStream = $response->getBody();
            $this->sseBuffer = '';
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
        $this->activeStream = null;

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
        $this->activeStream = null;
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

        while (null !== ($event = $this->extractSSEEvent())) {
            if (!empty(trim($event))) {
                $this->processSSEEvent($event);
            }
        }

        if ($this->activeStream->eof()) {
            // The stream ended without a trailing blank line: dispatch what is left.
            if (!empty(trim($this->sseBuffer))) {
                $this->processSSEEvent($this->sseBuffer);
            }

            $this->sseBuffer = '';
            $this->activeStream = null;
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
        $data = '';

        foreach (preg_split("/\r\n|\r|\n/", $event) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= trim(substr($line, 5));
            }
        }

        if (!empty($data)) {
            $this->handleMessage($data);
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
