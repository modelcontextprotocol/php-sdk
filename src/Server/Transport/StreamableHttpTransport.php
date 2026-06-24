<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends BaseTransport<ResponseInterface>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport extends BaseTransport
{
    public const SESSION_HEADER = 'Mcp-Session-Id';
    public const PROTOCOL_VERSION_HEADER = 'Mcp-Protocol-Version';

    /**
     * Upper bound on the request body read for a POST, guarding against memory
     * exhaustion from an oversized (or unbounded chunked) payload.
     */
    public const DEFAULT_MAX_BODY_BYTES = 4 * 1024 * 1024;

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;

    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<MiddlewareInterface>|null $middleware `null` installs {@see self::defaultMiddleware()}; `[]` disables all middleware
     */
    public function __construct(
        private ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?iterable $middleware = null,
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        parent::__construct($logger);

        if ($this->maxBodyBytes < 1) {
            throw new InvalidArgumentException('maxBodyBytes must be at least 1.');
        }

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        if (null === $middleware) {
            $this->middleware = self::defaultMiddleware();
        } else {
            $this->middleware = self::normalizeMiddleware($middleware);
            if ([] === $this->middleware) {
                $this->logger->warning('Streamable HTTP transport started with an empty middleware list. Default security protections (CORS, DNS rebinding, protocol version validation) are disabled. Pass null (or omit the argument) to use the secure defaults, or include them via [...StreamableHttpTransport::defaultMiddleware(), $yourMiddleware].');
            }
        }
    }

    /**
     * Secure default middleware stack applied when no `$middleware` is provided to the constructor.
     *
     * @return list<MiddlewareInterface>
     */
    public static function defaultMiddleware(): array
    {
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(),
            new ProtocolVersionMiddleware(),
        ];
    }

    public function send(string $data, array $context): void
    {
        $this->immediateResponse = $data;
        $this->immediateStatusCode = $context['status_code'] ?? 200;
    }

    public function listen(): ResponseInterface
    {
        $handler = new MiddlewareRequestHandler(
            $this->middleware,
            \Closure::fromCallable([$this, 'handleRequest']),
        );

        return $handler->handle($this->request);
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    protected function handlePostRequest(): ResponseInterface
    {
        $body = $this->readBody($this->request->getBody());
        if (null === $body) {
            $this->logger->warning('Rejected POST body exceeding the maximum allowed size.', ['limit' => $this->maxBodyBytes]);

            return $this->createErrorResponse(Error::forInvalidRequest(\sprintf('Request body exceeds the maximum allowed size of %d bytes.', $this->maxBodyBytes)), 413);
        }

        $this->handleMessage($body, $this->sessionId);

        if (null !== $this->immediateResponse) {
            $response = $this->responseFactory->createResponse($this->immediateStatusCode ?? 200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->immediateResponse));

            return $response;
        }

        if (null !== $this->sessionFiber) {
            $this->logger->info('Fiber suspended, handling via SSE.');

            return $this->createStreamedResponse();
        }

        return $this->createJsonResponse();
    }

    protected function handleDeleteRequest(): ResponseInterface
    {
        if (!$this->sessionId) {
            return $this->createErrorResponse(Error::forInvalidRequest(self::SESSION_HEADER.' header is required.'), 400);
        }

        $this->handleSessionEnd($this->sessionId);

        return $this->responseFactory->createResponse(200);
    }

    protected function createJsonResponse(): ResponseInterface
    {
        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            return $this->responseFactory->createResponse(202)
                ->withHeader('Content-Type', 'application/json');
        }

        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        if ($this->sessionId) {
            $response = $response->withHeader(self::SESSION_HEADER, $this->sessionId->toRfc4122());
        }

        return $response;
    }

    protected function createStreamedResponse(): ResponseInterface
    {
        $callback = function (): void {
            try {
                $this->logger->info('SSE: Starting request processing loop');

                while ($this->sessionFiber->isSuspended()) {
                    $this->flushOutgoingMessages($this->sessionId);

                    $pendingRequests = $this->getPendingRequests($this->sessionId);

                    if (empty($pendingRequests)) {
                        $yielded = $this->sessionFiber->resume();
                        $this->handleFiberYield($yielded, $this->sessionId);
                        continue;
                    }

                    $resumed = false;
                    foreach ($pendingRequests as $pending) {
                        $requestId = $pending['request_id'];
                        $timestamp = $pending['timestamp'];
                        $timeout = $pending['timeout'] ?? 120;

                        $response = $this->checkForResponse($requestId, $this->sessionId);

                        if (null !== $response) {
                            $yielded = $this->sessionFiber->resume($response);
                            $this->handleFiberYield($yielded, $this->sessionId);
                            $resumed = true;
                            break;
                        }

                        if (time() - $timestamp >= $timeout) {
                            $error = Error::forInternalError('Request timed out', $requestId);
                            $yielded = $this->sessionFiber->resume($error);
                            $this->handleFiberYield($yielded, $this->sessionId);
                            $resumed = true;
                            break;
                        }
                    }

                    if (!$resumed) {
                        usleep(100000);
                    } // Prevent tight loop
                }

                $this->handleFiberTermination();
            } finally {
                $this->sessionFiber = null;
            }
        };

        $stream = new CallbackStream($callback, $this->logger);
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($stream);

        if ($this->sessionId) {
            $response = $response->withHeader(self::SESSION_HEADER, $this->sessionId->toRfc4122());
        }

        return $response;
    }

    protected function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber->getReturn();

        if (null !== $finalResult) {
            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                echo "event: message\n";
                echo "data: {$encoded}\n\n";
                @ob_flush();
                flush();
            } catch (\JsonException $e) {
                $this->logger->error('SSE: Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }

        $this->sessionFiber = null;
    }

    protected function flushOutgoingMessages(?Uuid $sessionId): void
    {
        $messages = $this->getOutgoingMessages($sessionId);

        foreach ($messages as $message) {
            echo "event: message\n";
            echo "data: {$message['message']}\n\n";
            @ob_flush();
            flush();
        }
    }

    protected function createErrorResponse(Error $jsonRpcError, int $statusCode): ResponseInterface
    {
        $payload = json_encode($jsonRpcError, \JSON_THROW_ON_ERROR);
        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        if (405 === $statusCode) {
            $response = $response->withHeader('Allow', 'POST, DELETE, OPTIONS');
        }

        return $response;
    }

    /**
     * Reads the request body, bounded by {@see self::$maxBodyBytes}.
     *
     * Returns the body contents, or `null` when the payload exceeds the cap. When
     * the stream advertises a size we reject up-front; otherwise (e.g. chunked
     * transfer with unknown size) we read incrementally and stop at the cap so an
     * unbounded stream cannot exhaust memory.
     */
    private function readBody(StreamInterface $body): ?string
    {
        $size = $body->getSize();
        if (null !== $size && $size > $this->maxBodyBytes) {
            return null;
        }

        $contents = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' === $chunk) {
                break;
            }

            $contents .= $chunk;
            if (\strlen($contents) > $this->maxBodyBytes) {
                return null;
            }
        }

        return $contents;
    }

    /**
     * @param iterable<MiddlewareInterface> $middleware
     *
     * @return list<MiddlewareInterface>
     */
    private static function normalizeMiddleware(iterable $middleware): array
    {
        $normalized = [];
        foreach ($middleware as $m) {
            if (!$m instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Streamable HTTP middleware must implement Psr\\Http\\Server\\MiddlewareInterface.');
            }
            $normalized[] = $m;
        }

        return $normalized;
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $sessionIdString = $request->getHeaderLine(self::SESSION_HEADER);
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;

        return match ($request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405),
        };
    }
}
