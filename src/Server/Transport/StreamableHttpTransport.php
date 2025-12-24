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
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends BaseTransport<ResponseInterface>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport extends BaseTransport
{
    protected ResponseFactoryInterface $responseFactory;
    protected StreamFactoryInterface $streamFactory;

    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;

    /** @var array<string, string> */
    protected array $corsHeaders;

    /**
     * @param array<string, string> $corsHeaders
     */
    public function __construct(
        protected readonly ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $corsHeaders = [],
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
        $sessionIdString = $this->request->getHeaderLine('Mcp-Session-Id');
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->corsHeaders = array_merge([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ], $corsHeaders);
    }

    public function send(string $data, array $context): void
    {
        $this->immediateResponse = $data;
        $this->immediateStatusCode = $context['status_code'] ?? 200;
    }

    public function listen(): ResponseInterface
    {
        return match ($this->request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405),
        };
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->withCorsHeaders($this->responseFactory->createResponse(204));
    }

    protected function handlePostRequest(): ResponseInterface
    {
        $body = $this->request->getBody()->getContents();
        $this->handleMessage($body, $this->sessionId);

        if (null !== $this->immediateResponse) {
            $response = $this->responseFactory->createResponse($this->immediateStatusCode ?? 200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->immediateResponse));

            return $this->withCorsHeaders($response);
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

        return $this->withCorsHeaders($this->responseFactory->createResponse(200));
    }

    protected function createJsonResponse(): ResponseInterface
    {
        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            return $this->withCorsHeaders($this->responseFactory->createResponse(202));
        }

        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        if ($this->sessionId) {
            $response = $response->withHeader('Mcp-Session-Id', $this->sessionId->toRfc4122());
        }

        return $this->withCorsHeaders($response);
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

        return $this->withCorsHeaders($response);
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

        return $this->withCorsHeaders($response);
    }

    protected function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
