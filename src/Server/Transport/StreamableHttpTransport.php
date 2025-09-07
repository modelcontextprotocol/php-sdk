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
    private $messageHandler = null;
    private $outgoingMessages = [];

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function initialize(): void {}

    public function setMessageHandler(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function send(string $data): void
    {
        $this->outgoingMessages[] = $data;
    }

    public function listen(): mixed
    {
        if ($this->messageHandler === null) {
            $this->logger->error('Cannot listen without a message handler. Did you forget to call Server::connect()?');
            return $this->createErrorResponse(Error::forInternalError('Internal Server Error: Transport not configured.'), 500);
        }

        switch ($this->request->getMethod()) {
            case 'POST':
                $body = $this->request->getBody()->getContents();
                if (empty($body)) {
                    return $this->createErrorResponse(Error::forInvalidRequest('Bad Request: Empty request body.'), 400);
                }

                call_user_func($this->messageHandler, $body);
                break;

            case 'GET':
            case 'DELETE':
                return $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405);

            default:
                return $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405)
                    ->withHeader('Allow', 'POST');
        }

        return $this->buildResponse();
    }

    public function close(): void {}

    private function buildResponse(): ResponseInterface
    {
        $hasRequestsInInput = !empty($this->request->getBody()->getContents());
        $hasResponsesInOutput = !empty($this->outgoingMessages);

        if ($hasRequestsInInput && !$hasResponsesInOutput) {
            return $this->responseFactory->createResponse(202);
        }

        $responseBody = count($this->outgoingMessages) === 1
            ? $this->outgoingMessages[0]
            : '[' . implode(',', $this->outgoingMessages) . ']';

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));
    }

    private function createErrorResponse(Error $jsonRpcErrpr, int $statusCode): ResponseInterface
    {
        $errorPayload = json_encode($jsonRpcErrpr, \JSON_THROW_ON_ERROR);
        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($errorPayload)));
    }
}
