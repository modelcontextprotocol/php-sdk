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

use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ResourceSubscribeRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\ResourceSubscribeHandler;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResourceSubscribeHandlerTest extends TestCase
{
    private ResourceSubscribeHandler $handler;
    private RegistryInterface&MockObject $registry;
    private SubscriptionManagerInterface&MockObject $subscriptionManager;
    private SessionInterface&MockObject $session;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ResourceSubscribeHandler($this->registry, $this->subscriptionManager, $this->logger);
    }

    public function testSupportsResourceSubscribeRequest(): void
    {
        $request = $this->createRequest('file://test.txt');

        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandleSuccessfulSubscribe(): void
    {
        $uri = 'file://test.txt';
        $request = $this->createRequest($uri);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($this->createMock(\Mcp\Capability\Registry\ResourceReference::class));

        $this->subscriptionManager
            ->expects($this->once())
            ->method('subscribe')
            ->with($this->session, $uri);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
    }

    /**
     * @dataProvider provideResourceNotFoundRevisions
     */
    public function testHandleResourceNotFoundIsProtocolVersionAware(?string $negotiated, int $expectedCode): void
    {
        $uri = 'file://nonexistent/file.txt';
        $request = $this->createRequest($uri);
        $exception = new ResourceNotFoundException($uri);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $this->session
            ->method('get')
            ->with('protocol_version')
            ->willReturn($negotiated);

        $this->subscriptionManager
            ->expects($this->never())
            ->method('subscribe');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedCode, $response->code);
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function provideResourceNotFoundRevisions(): iterable
    {
        yield 'no negotiated revision' => [null, Error::RESOURCE_NOT_FOUND];
        yield '2025-11-25' => ['2025-11-25', Error::RESOURCE_NOT_FOUND];
        yield '2026-07-28' => ['2026-07-28', Error::INVALID_PARAMS];
    }

    private function createRequest(string $uri): ResourceSubscribeRequest
    {
        return ResourceSubscribeRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => ResourceSubscribeRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'uri' => $uri,
            ],
        ]);
    }
}
