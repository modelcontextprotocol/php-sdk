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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP Transport for MCP 2026-07-28 stateless mode.
 *
 * No Mcp-Session-Id header, no initialize handshake, no DELETE endpoint.
 * Each POST request is self-contained and stateless.
 *
 * @phpstan-ignore missingType.generics
 */
class StatelessStreamableHttpTransport extends BaseTransport
{
    public const DEFAULT_MAX_BODY_BYTES = 4 * 1024 * 1024;
    public const PROTOCOL_VERSION_HEADER = 'Mcp-Protocol-Version';

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;

    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<MiddlewareInterface>|null $middleware `null` installs default middleware; `[]` disables all middleware
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
                $this->logger->warning('Stateless HTTP transport started with an empty middleware list. Default security protections are disabled.');
            }
        }
    }

    /**
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

    protected function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return match ($request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'POST' => $this->handlePostRequest(),
            default => $this->createErrorResponse(
                Error::forInvalidRequest('Method Not Allowed'),
                405
            ),
        };
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    protected function handlePostRequest(): ResponseInterface
    {
        $body = $this->readBody($this->request->getBody());
        if (null === $body) {
            return $this->createErrorResponse(
                Error::forInvalidRequest(\sprintf('Request body exceeds the maximum allowed size of %d bytes.', $this->maxBodyBytes)),
                413
            );
        }

        // Stateless: no session ID, each request is independent
        $this->handleMessage($body, null);

        if (null !== $this->immediateResponse) {
            return $this->responseFactory
                ->createResponse($this->immediateStatusCode ?? 200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->immediateResponse));
        }

        if (null !== $this->sessionFiber) {
            return $this->createStreamedResponse();
        }

        return $this->createJsonResponse();
    }

    protected function createJsonResponse(): ResponseInterface
    {
        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            return $this->responseFactory
                ->createResponse(202)
                ->withHeader('Content-Type', 'application/json');
        }

        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));
    }

    protected function createStreamedResponse(): ResponseInterface
    {
        $callback = function (): void {
            try {
                $this->logger->info('SSE: Starting stateless request processing loop');

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
                    }
                }

                $this->handleFiberTermination();
            } finally {
                $this->sessionFiber = null;
            }
        };

        $stream = new CallbackStream($callback, $this->logger);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($stream);
    }

    protected function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber?->getReturn();

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

    protected function flushOutgoingMessages(?\Symfony\Component\Uid\Uuid $sessionId): void
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

        $response = $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        if (405 === $statusCode) {
            $response = $response->withHeader('Allow', 'POST, OPTIONS');
        }

        return $response;
    }

    private function readBody(\Psr\Http\Message\StreamInterface $body): ?string
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
                throw new InvalidArgumentException('Streamable HTTP middleware must implement Psr\Http\Server\MiddlewareInterface.');
            }
            $normalized[] = $m;
        }

        return $normalized;
    }
}
