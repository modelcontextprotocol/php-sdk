<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Server\RequestHandler;

use Mcp\Capability\Registry;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Resource;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server\RequestHandler\ListResourcesHandler;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ListResourcesHandlerTest extends TestCase
{
    private Registry $registry;
    private ListResourcesHandler $handler;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListResourcesHandler($this->registry, pageSize: 3); // Use small page size for testing
    }

    #[TestDox('Returns first page when no cursor provided')]
    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(3, $response->result->resources);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('resource://test/resource_0', $response->result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_1', $response->result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_2', $response->result->resources[2]->uri);
    }

    #[TestDox('Returns paginated resources with cursor')]
    public function testReturnsPaginatedResourcesWithCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        $request = $this->createListResourcesRequest(cursor: null);

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(3, $response->result->resources);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('resource://test/resource_0', $response->result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_1', $response->result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_2', $response->result->resources[2]->uri);
    }

    #[TestDox('Returns second page with cursor')]
    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest);
        
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResponse->result->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(3, $response->result->resources);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('resource://test/resource_3', $response->result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_4', $response->result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_5', $response->result->resources[2]->uri);
    }

    #[TestDox('Returns last page with null cursor')]
    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest);
        
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResponse->result->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(2, $response->result->resources);
        $this->assertNull($response->result->nextCursor);

        $this->assertEquals('resource://test/resource_3', $response->result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_4', $response->result->resources[1]->uri);
    }

    #[TestDox('Handles empty registry')]
    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(0, $response->result->resources);
        $this->assertNull($response->result->nextCursor);
    }

    #[TestDox('Throws exception for invalid cursor')]
    public function testThrowsExceptionForInvalidCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $request = $this->createListResourcesRequest(cursor: 'invalid-cursor');

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request);
    }

    #[TestDox('Throws exception for cursor beyond bounds')]
    public function testThrowsExceptionForCursorBeyondBounds(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $outOfBoundsCursor = base64_encode('100');
        $request = $this->createListResourcesRequest(cursor: $outOfBoundsCursor);

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request);
    }

    #[TestDox('Handles cursor at exact boundary')]
    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addResourcesToRegistry(6);
        $exactBoundaryCursor = base64_encode('6');
        $request = $this->createListResourcesRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListResourcesResult::class, $response->result);
        $this->assertCount(0, $response->result->resources);
        $this->assertNull($response->result->nextCursor);
    }

    #[TestDox('Maintains stable cursors across calls')]
    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        
        // Act
        $request = $this->createListResourcesRequest();
        $response1 = $this->handler->handle($request);
        $response2 = $this->handler->handle($request);

        // Assert
        $this->assertEquals($response1->result->nextCursor, $response2->result->nextCursor);
        $this->assertEquals($response1->result->resources, $response2->result->resources);
    }

    private function addResourcesToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $resource = new Resource(
                uri: "resource://test/resource_$i",
                name: "resource_$i",
                description: "Test resource $i"
            );
            // Use a simple callable as handler
            $this->registry->registerResource($resource, fn() => null);
        }
    }

    private function createListResourcesRequest(?string $cursor = null): ListResourcesRequest
    {
        $mock = $this->getMockBuilder(ListResourcesRequest::class)
            ->setConstructorArgs([$cursor])
            ->onlyMethods(['getId'])
            ->getMock();
        
        $mock->method('getId')->willReturn('test-request-id');
        
        return $mock;
    }
}