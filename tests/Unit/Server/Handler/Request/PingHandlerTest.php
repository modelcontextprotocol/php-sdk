<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\PingHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

class PingHandlerTest extends TestCase
{
    private PingHandler $handler;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->handler = new PingHandler();
    }

    public function testSupportsPingRequest(): void
    {
        $request = $this->createPingRequest();

        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandlePingRequest(): void
    {
        $request = $this->createPingRequest();

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
    }

    public function testHandleMultiplePingRequests(): void
    {
        $request1 = $this->createPingRequest();
        $request2 = $this->createPingRequest();

        $response1 = $this->handler->handle($request1, $this->session);
        $response2 = $this->handler->handle($request2, $this->session);

        $this->assertInstanceOf(Response::class, $response1);
        $this->assertInstanceOf(Response::class, $response2);
        $this->assertInstanceOf(EmptyResult::class, $response1->result);
        $this->assertInstanceOf(EmptyResult::class, $response2->result);
        $this->assertEquals($request1->getId(), $response1->id);
        $this->assertEquals($request2->getId(), $response2->id);
    }

    public function testHandlerHasNoSideEffects(): void
    {
        $request = $this->createPingRequest();

        // Handle same request multiple times
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Both responses should be identical
        $this->assertEquals($response1->id, $response2->id);
        $this->assertEquals(
            \get_class($response1->result),
            \get_class($response2->result),
        );
    }

    public function testEmptyResultIsCorrectType(): void
    {
        $request = $this->createPingRequest();
        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(EmptyResult::class, $response->result);

        // Verify EmptyResult serializes to empty object
        $serialized = json_encode($response->result);
        $this->assertEquals('{}', $serialized);
    }

    public function testHandlerIsStateless(): void
    {
        $handler1 = new PingHandler();
        $handler2 = new PingHandler();

        $request = $this->createPingRequest();

        $response1 = $handler1->handle($request, $this->session);
        $response2 = $handler2->handle($request, $this->session);

        // Both handlers should produce equivalent results
        $this->assertEquals($response1->id, $response2->id);
        $this->assertEquals(
            \get_class($response1->result),
            \get_class($response2->result),
        );
    }

    public function testSupportsMethodIsConsistent(): void
    {
        $request = $this->createPingRequest();

        // Multiple calls to supports should return same result
        $this->assertTrue($this->handler->supports($request));
        $this->assertTrue($this->handler->supports($request));
        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandlerCanBeReused(): void
    {
        $requests = [];
        $responses = [];

        // Create multiple ping requests
        for ($i = 0; $i < 5; ++$i) {
            $requests[$i] = $this->createPingRequest();
            $responses[$i] = $this->handler->handle($requests[$i], $this->session);
        }

        // All responses should be valid
        foreach ($responses as $i => $response) {
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals($requests[$i]->getId(), $response->id);
            $this->assertInstanceOf(EmptyResult::class, $response->result);
        }
    }

    private function createPingRequest(): Request
    {
        return PingRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => PingRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
        ]);
    }
}
