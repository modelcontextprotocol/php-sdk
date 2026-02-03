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
use Mcp\Schema\JsonRpc\Error;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends BaseTransport<ResponseInterface>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport extends BaseTransport
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;

    /** @var array<string, string> */
    private array $corsHeaders;

    /** @var list<MiddlewareInterface> */
    private array $middlewares = [];

    /**
     * @param array<string, string> $corsHeaders
     * @param iterable<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $corsHeaders = [],
        ?LoggerInterface $logger = null,
        iterable $middlewares = [],
    ) {
        parent::__construct($logger);

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->corsHeaders = array_merge([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ], $corsHeaders);

        foreach ($middlewares as $middleware) {
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException('Streamable HTTP middleware must implement Psr\\Http\\Server\\MiddlewareInterface.');
            }
            $this->middlewares[] = $middleware;
        }
    }

    public function send(string $data, array $context): void
    {
        $this->immediateResponse = $data;
        $this->immediateStatusCode = $context['status_code'] ?? 200;
    }

    public function listen(): ResponseInterface
    {
        $handler = $this->createRequestHandler();
        $response = $handler->handle($this->request);

        return $this->withCorsHeaders($response);
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    protected function handlePostRequest(): ResponseInterface
    {
        $body = $this->request->getBody()->getContents();
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
            return $this->createErrorResponse(Error::forInvalidRequest('Mcp-Session-Id header is required.'), 400);
        }

        $this->handleSessionEnd($this->sessionId);

        return $this->responseFactory->createResponse(200);
    }

    protected function createJsonResponse(): ResponseInterface
    {
        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            return $this->responseFactory->createResponse(202);
        }

        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        if ($this->sessionId) {
            $response = $response->withHeader('Mcp-Session-Id', $this->sessionId->toRfc4122());
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
            $response = $response->withHeader('Mcp-Session-Id', $this->sessionId->toRfc4122());
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

        return $response;
    }

    protected function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->corsHeaders as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $sessionIdString = $request->getHeaderLine('Mcp-Session-Id');
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;

        return match ($request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405),
        };
    }

    private function createRequestHandler(): RequestHandlerInterface
    {
        /**
         * @see self::handleRequest
         */
        $handler = new class(\Closure::fromCallable([$this, 'handleRequest'])) implements RequestHandlerInterface {
            public function __construct(private \Closure $handler)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };

        foreach (array_reverse($this->middlewares) as $middleware) {
            $handler = new class($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface $middleware,
                    private RequestHandlerInterface $handler,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->handler);
                }
            };
        }

        return $handler;
    }
}
