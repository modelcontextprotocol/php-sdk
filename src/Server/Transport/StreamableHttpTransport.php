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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\TransportInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport implements TransportInterface
{
    /** @var array<string, array<callable>> */
    private array $listeners = [];

    /** @var string[] */
    private array $outgoingMessages = [];

    private array $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Last-Event-ID, Authorization, Accept',
    ];

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function initialize(): void {}

    public function on(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed ...$args): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener(...$args);
        }
    }

    public function send(string $data): void
    {
        $this->outgoingMessages[] = $data;
    }

    public function listen(): mixed
    {

        return match ($this->request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'GET' => $this->handleGetRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->handleUnsupportedRequest(),
        };
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
            return $this->createErrorResponse($error, 406);
        }

        if (!str_contains($this->request->getHeaderLine('Content-Type'), 'application/json')) {
            $error = Error::forInvalidRequest('Unsupported Media Type: Content-Type must be application/json.');
            return $this->createErrorResponse($error, 415);
        }

        $body = $this->request->getBody()->getContents();
        if (empty($body)) {
            $error = Error::forInvalidRequest('Bad Request: Empty request body.');
            return $this->createErrorResponse($error, 400);
        }

        $this->emit('message', $body);

        $hasRequestsInInput = str_contains($body, '"id"');
        $hasResponsesInOutput = !empty($this->outgoingMessages);

        if ($hasRequestsInInput && !$hasResponsesInOutput) {
            return $this->withCorsHeaders($this->responseFactory->createResponse(202));
        }

        $responseBody = count($this->outgoingMessages) === 1
            ? $this->outgoingMessages[0]
            : '[' . implode(',', $this->outgoingMessages) . ']';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        return $this->withCorsHeaders($response);
    }

    protected function handleGetRequest(): ResponseInterface
    {
        $response = $this->createErrorResponse(Error::forInvalidRequest('Not Yet Implemented'), 501);
        return $this->withCorsHeaders($response);
    }

    protected function handleDeleteRequest(): ResponseInterface
    {
        $response = $this->createErrorResponse(Error::forInvalidRequest('Not Yet Implemented'), 501);
        return $this->withCorsHeaders($response);
    }

    protected function handleUnsupportedRequest(): ResponseInterface
    {
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

    public function close(): void {}
}
