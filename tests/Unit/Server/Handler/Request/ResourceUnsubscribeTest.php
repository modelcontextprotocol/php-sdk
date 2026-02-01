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

use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ResourceUnsubscribeRequest;
use Mcp\Schema\Resource;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\ResourceUnsubscribeHandler;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResourceUnsubscribeTest extends TestCase
{
    private ResourceUnsubscribeHandler $handler;
    private RegistryInterface&MockObject $registry;
    private SessionInterface&MockObject $session;
    private SubscriptionManagerInterface&MockObject $subscriptionManager;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new ResourceUnsubscribeHandler($this->registry, $this->subscriptionManager);
    }

    #[TestDox('Client can unsubscribe from a resource')]
    public function testClientCanUnsubscribeFromAResource(): void
    {
        // Arrange
        $uri = 'file://documents/readme.txt';
        $request = $this->createResourceUnsubscribeRequest($uri);
        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'text/plain'), []])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->subscriptionManager->expects($this->once())
            ->method('unsubscribe')
            ->with($this->session, $uri);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
    }

    #[TestDox('Gracefully handle duplicate unsubscription from a resource')]
    public function testDuplicateUnSubscriptionIsGracefullyHandled(): void
    {
        // Arrange
        $uri = 'file://documents/readme.txt';
        $request = $this->createResourceUnsubscribeRequest($uri);
        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'text/plain'), []])
            ->getMock();

        $this->registry
            ->expects($this->exactly(2))
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->subscriptionManager
            ->expects($this->exactly(2))
            ->method('unsubscribe')
            ->with($this->session, $uri);

        // Act
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Assert
        $this->assertInstanceOf(Response::class, $response1);
        $this->assertInstanceOf(Response::class, $response2);
        $this->assertEquals($request->getId(), $response1->id);
        $this->assertEquals($request->getId(), $response2->id);
        $this->assertInstanceOf(EmptyResult::class, $response1->result);
        $this->assertInstanceOf(EmptyResult::class, $response2->result);
    }

    #[TestDox('Unsubscription from a resource with an invalid uri throws ResourceNotException')]
    public function testHandleUnsubscribeResourceNotFoundException(): void
    {
        $uri = 'file://missing/file.txt';
        $request = $this->createResourceUnsubscribeRequest($uri);
        $exception = new ResourceNotFoundException($uri);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals(\sprintf('Resource not found for uri: "%s".', $uri), $response->message);
    }

    #[TestDox('Unsubscription from a resource with an empty uri throws InvalidArgumentException')]
    public function testUnsubscribeWithEmptyUriThrowsError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "uri" parameter for resources/unsubscribe.');

        $this->createResourceUnsubscribeRequest('');
    }

    private function createResourceUnsubscribeRequest(string $uri): ResourceUnsubscribeRequest
    {
        return ResourceUnsubscribeRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => ResourceUnsubscribeRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'uri' => $uri,
            ],
        ]);
    }
}
