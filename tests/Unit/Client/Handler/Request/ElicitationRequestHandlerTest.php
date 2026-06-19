<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Handler\Request;

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Exception\ElicitationException;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\ElicitResult;
use PHPUnit\Framework\TestCase;

class ElicitationRequestHandlerTest extends TestCase
{
    public function testSupportsElicitRequest(): void
    {
        $handler = new ElicitationRequestHandler($this->callbackReturning(
            new ElicitResult(ElicitAction::Decline),
        ));

        $this->assertTrue($handler->supports($this->createElicitRequest()));
    }

    public function testDoesNotSupportOtherRequests(): void
    {
        $handler = new ElicitationRequestHandler($this->callbackReturning(
            new ElicitResult(ElicitAction::Decline),
        ));

        $ping = PingRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => PingRequest::getMethod(),
            'id' => 'ping-1',
        ]);

        $this->assertFalse($handler->supports($ping));
    }

    public function testHandleReturnsResponseOnAccept(): void
    {
        $result = new ElicitResult(ElicitAction::Accept, ['name' => 'Ada']);
        $handler = new ElicitationRequestHandler($this->callbackReturning($result));

        $request = $this->createElicitRequest();
        $response = $handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($request->getId(), $response->id);
        $this->assertSame($result, $response->result);
    }

    public function testHandleReturnsErrorOnElicitationException(): void
    {
        $handler = new ElicitationRequestHandler($this->callbackThrowing(
            new ElicitationException('user input unavailable'),
        ));

        $request = $this->createElicitRequest();
        $response = $handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame($request->getId(), $response->getId());
        $this->assertSame('user input unavailable', $response->message);
    }

    public function testHandleReturnsGenericErrorOnThrowable(): void
    {
        $handler = new ElicitationRequestHandler($this->callbackThrowing(
            new \RuntimeException('boom'),
        ));

        $request = $this->createElicitRequest();
        $response = $handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame($request->getId(), $response->getId());
        $this->assertSame('Error while processing elicitation', $response->message);
    }

    private function createElicitRequest(): ElicitRequest
    {
        return ElicitRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => ElicitRequest::getMethod(),
            'id' => 'elicit-'.uniqid(),
            'params' => [
                'message' => 'Please provide your name.',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'title' => 'Name'],
                    ],
                    'required' => ['name'],
                ],
            ],
        ]);
    }

    private function callbackReturning(ElicitResult $result): ElicitationCallbackInterface
    {
        return new class($result) implements ElicitationCallbackInterface {
            public function __construct(private readonly ElicitResult $result)
            {
            }

            public function __invoke(ElicitRequest $request): ElicitResult
            {
                return $this->result;
            }
        };
    }

    private function callbackThrowing(\Throwable $exception): ElicitationCallbackInterface
    {
        return new class($exception) implements ElicitationCallbackInterface {
            public function __construct(private readonly \Throwable $exception)
            {
            }

            public function __invoke(ElicitRequest $request): ElicitResult
            {
                throw $this->exception;
            }
        };
    }
}
