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
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class HttpTransport extends BaseTransport
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    private ?string $sessionId = null;

    /** @var McpFiber|null */
    private ?\Fiber $activeFiber = null;

    /** @var (callable(float, ?float, ?string): void)|null */
    private $activeProgressCallback;

    /** @var StreamInterface|null Active SSE stream being read */
    private ?StreamInterface $activeStream = null;

    /** @var string Buffer for incomplete SSE data */
    private string $sseBuffer = '';

    /**
     * @param string                       $endpoint       The MCP server endpoint URL
     * @param array<string, string>        $headers        Additional headers to send
     * @param ClientInterface|null         $httpClient     PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);

        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function connectAndInitialize(int $timeout): void
    {
        $this->activeFiber = new \Fiber(fn () => $this->handleInitialize());

        $deadline = time() + $timeout;
        $this->activeFiber->start();

        while (!$this->activeFiber->isTerminated()) {
            if (time() >= $deadline) {
                throw new TimeoutException('Initialization timed out after '.$timeout.' seconds');
            }
            $this->tick();
        }

        $result = $this->activeFiber->getReturn();
        $this->activeFiber = null;

        if ($result instanceof Error) {
            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('HTTP client connected and initialized', ['endpoint' => $this->endpoint]);
    }

    public function send(string $data, array $context): void
    {
        $request = $this->requestFactory->createRequest('POST', $this->endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream($data));

        if (null !== $this->sessionId) {
            $request = $request->withHeader('Mcp-Session-Id', $this->sessionId);
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

        $contentType = $response->getHeaderLine('Content-Type');

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
                $this->logger->warning('Failed to close session', ['error' => $e->getMessage()]);
            }
        }

        $this->sessionId = null;
        $this->activeStream = null;
        $this->handleClose('Transport closed');
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
                $this->sseBuffer .= $chunk;
            }
        }

        while (false !== ($pos = strpos($this->sseBuffer, "\n\n"))) {
            $event = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 2);

            if (!empty(trim($event))) {
                $this->processSSEEvent($event);
            }
        }

        if ($this->activeStream->eof() && empty($this->sseBuffer)) {
            $this->activeStream = null;
        }
    }

    /**
     * Parse a single SSE event and handle the message.
     */
    private function processSSEEvent(string $event): void
    {
        $data = '';

        foreach (explode("\n", $event) as $line) {
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
        if (null === $this->activeProgressCallback || null === $this->session) {
            return;
        }

        $updates = $this->session->consumeProgressUpdates();

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

        if (null === $this->session) {
            return;
        }

        $pendingRequests = $this->session->getPendingRequests();

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'];

            $response = $this->session->consumeResponse($requestId);

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
