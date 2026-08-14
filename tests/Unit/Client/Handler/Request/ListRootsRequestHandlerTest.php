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

use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Exception\RootsException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use PHPUnit\Framework\TestCase;

final class ListRootsRequestHandlerTest extends TestCase
{
    public function testSupportsListRootsRequest(): void
    {
        $handler = new ListRootsRequestHandler($this->createCallback(new ListRootsResult([])));

        $this->assertTrue($handler->supports(new ListRootsRequest()));
    }

    public function testDoesNotSupportOtherRequests(): void
    {
        $handler = new ListRootsRequestHandler($this->createCallback(new ListRootsResult([])));

        $this->assertFalse($handler->supports(new PingRequest()));
    }

    public function testHandleReturnsRootsFromCallback(): void
    {
        $result = new ListRootsResult([
            new Root('file:///home/user/project', 'project'),
            new Root('file:///tmp'),
        ]);

        $handler = new ListRootsRequestHandler($this->createCallback($result));

        $request = (new ListRootsRequest())->withId('req-1');
        $response = $handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('req-1', $response->getId());
        $this->assertSame($result, $response->result);
    }

    public function testHandleReturnsErrorWhenCallbackThrows(): void
    {
        $handler = new ListRootsRequestHandler(new class implements RootsCallbackInterface {
            public function __invoke(ListRootsRequest $request): ListRootsResult
            {
                throw new \RuntimeException('boom');
            }
        });

        $request = (new ListRootsRequest())->withId('req-2');
        $response = $handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame('req-2', $response->getId());
        $this->assertSame('Error while listing roots', $response->message);
    }

    public function testHandleForwardsRootsExceptionMessage(): void
    {
        $handler = new ListRootsRequestHandler(new class implements RootsCallbackInterface {
            public function __invoke(ListRootsRequest $request): ListRootsResult
            {
                throw new RootsException('permission denied');
            }
        });

        $request = (new ListRootsRequest())->withId('req-3');
        $response = $handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame('req-3', $response->getId());
        $this->assertSame('permission denied', $response->message);
    }

    private function createCallback(ListRootsResult $result): RootsCallbackInterface
    {
        return new class($result) implements RootsCallbackInterface {
            public function __construct(private readonly ListRootsResult $result)
            {
            }

            public function __invoke(ListRootsRequest $request): ListRootsResult
            {
                return $this->result;
            }
        };
    }
}
