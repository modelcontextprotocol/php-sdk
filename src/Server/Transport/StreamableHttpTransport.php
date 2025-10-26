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
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @implements TransportInterface<ResponseInterface>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport implements TransportInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var callable(string, ?Uuid): void */
    private $messageListener;

    /** @var callable(Uuid): void */
    private $sessionEndListener;

    private ?Uuid $sessionId = null;

    /** @var string[] */
    private array $outgoingMessages = [];
    private ?Uuid $outgoingSessionId = null;
    private ?int $outgoingStatusCode = null;

    /** @var array<string, string> */
    private array $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
    ];

    public function __construct(
        private readonly ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $sessionIdString = $this->request->getHeaderLine('Mcp-Session-Id');
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function initialize(): void
    {
    }

    public function send(string $data, array $context): void
    {
        $this->outgoingMessages[] = $data;

        if (isset($context['session_id'])) {
            $this->outgoingSessionId = $context['session_id'];
        }

        if (isset($context['status_code']) && \is_int($context['status_code'])) {
            $this->outgoingStatusCode = $context['status_code'];
        }

        $this->logger->debug('Sending data to client via StreamableHttpTransport.', [
            'data' => $data,
            'session_id' => $this->outgoingSessionId?->toRfc4122(),
            'status_code' => $this->outgoingStatusCode,
        ]);
    }

    public function listen(): ResponseInterface
    {
        return match ($this->request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'GET' => $this->handleGetRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->handleUnsupportedRequest(),
        };
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionEndListener = $listener;
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->withCorsHeaders($this->responseFactory->createResponse(204));
    }

    protected function handlePostRequest(): ResponseInterface
    {
        $acceptHeader = $this->request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'application/json') || !str_contains($acceptHeader, 'text/event-stream')) {
            $error = Error::forInvalidRequest('Not Acceptable: Client must accept both application/json and text/event-stream.');
            $this->logger->warning('Client does not accept required content types.', ['accept' => $acceptHeader]);

            return $this->createErrorResponse($error, 406);
        }

        if (!str_contains($this->request->getHeaderLine('Content-Type'), 'application/json')) {
            $error = Error::forInvalidRequest('Unsupported Media Type: Content-Type must be application/json.');
            $this->logger->warning('Client sent unsupported content type.', ['content_type' => $this->request->getHeaderLine('Content-Type')]);

            return $this->createErrorResponse($error, 415);
        }

        $body = $this->request->getBody()->getContents();
        if (empty($body)) {
            $error = Error::forInvalidRequest('Bad Request: Empty request body.');
            $this->logger->warning('Client sent empty request body.');

            return $this->createErrorResponse($error, 400);
        }

        $this->logger->debug('Received message on StreamableHttpTransport.', [
            'body' => $body,
            'session_id' => $this->sessionId?->toRfc4122(),
        ]);

        if (\is_callable($this->messageListener)) {
            \call_user_func($this->messageListener, $body, $this->sessionId);
        }

        if (empty($this->outgoingMessages)) {
            return $this->withCorsHeaders($this->responseFactory->createResponse(202));
        }

        $responseBody = 1 === \count($this->outgoingMessages)
            ? $this->outgoingMessages[0]
            : '['.implode(',', $this->outgoingMessages).']';

        $status = $this->outgoingStatusCode ?? 200;

        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        if ($this->outgoingSessionId) {
            $response = $response->withHeader('Mcp-Session-Id', $this->outgoingSessionId->toRfc4122());
        }

        return $this->withCorsHeaders($response);
    }

    protected function handleGetRequest(): ResponseInterface
    {
        $response = $this->createErrorResponse(Error::forInvalidRequest('Not Yet Implemented'), 405);

        return $this->withCorsHeaders($response);
    }

    protected function handleDeleteRequest(): ResponseInterface
    {
        if (!$this->sessionId) {
            $error = Error::forInvalidRequest('Bad Request: Mcp-Session-Id header is required for DELETE requests.');
            $this->logger->warning('DELETE request received without session ID.');

            return $this->createErrorResponse($error, 400);
        }

        if (\is_callable($this->sessionEndListener)) {
            \call_user_func($this->sessionEndListener, $this->sessionId);
        }

        return $this->withCorsHeaders($this->responseFactory->createResponse(204));
    }

    protected function handleUnsupportedRequest(): ResponseInterface
    {
        $this->logger->warning('Unsupported HTTP method received.', [
            'method' => $this->request->getMethod(),
        ]);

        $response = $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405);

        return $this->withCorsHeaders($response);
    }

    protected function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    protected function createErrorResponse(Error $jsonRpcError, int $statusCode): ResponseInterface
    {
        $errorPayload = json_encode($jsonRpcError, \JSON_THROW_ON_ERROR);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($errorPayload));
    }

    public function close(): void
    {
    }
}
