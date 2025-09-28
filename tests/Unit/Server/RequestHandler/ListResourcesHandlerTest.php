<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\RequestHandler;

use Mcp\Capability\Registry;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Resource;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server\Handler\Request\ListResourcesHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ListResourcesHandlerTest extends TestCase
{
    private Registry $registry;
    private ListResourcesHandler $handler;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListResourcesHandler($this->registry, pageSize: 3); // Use small page size for testing
        $this->session = new Session(new InMemorySessionStore());
    }

    #[TestDox('Returns first page when no cursor provided')]
    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(3, $result->resources);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('resource://test/resource_0', $result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_1', $result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_2', $result->resources[2]->uri);
    }

    #[TestDox('Returns paginated resources with cursor')]
    public function testReturnsPaginatedResourcesWithCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        $request = $this->createListResourcesRequest(cursor: null);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(3, $result->resources);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('resource://test/resource_0', $result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_1', $result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_2', $result->resources[2]->uri);
    }

    #[TestDox('Returns second page with cursor')]
    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListResourcesResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(3, $result->resources);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('resource://test/resource_3', $result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_4', $result->resources[1]->uri);
        $this->assertEquals('resource://test/resource_5', $result->resources[2]->uri);
    }

    #[TestDox('Returns last page with null cursor')]
    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListResourcesResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(2, $result->resources);
        $this->assertNull($result->nextCursor);

        $this->assertEquals('resource://test/resource_3', $result->resources[0]->uri);
        $this->assertEquals('resource://test/resource_4', $result->resources[1]->uri);
    }

    #[TestDox('Handles empty registry')]
    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(0, $result->resources);
        $this->assertNull($result->nextCursor);
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
        $this->handler->handle($request, $this->session);
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
        $this->handler->handle($request, $this->session);
    }

    #[TestDox('Handles cursor at exact boundary')]
    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addResourcesToRegistry(6);
        $exactBoundaryCursor = base64_encode('6');
        $request = $this->createListResourcesRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourcesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(0, $result->resources);
        $this->assertNull($result->nextCursor);
    }

    #[TestDox('Maintains stable cursors across calls')]
    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);

        // Act
        $request = $this->createListResourcesRequest();
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourcesResult $result1 */
        $result1 = $response1->result;
        /** @var ListResourcesResult $result2 */
        $result2 = $response2->result;
        $this->assertEquals($result1->nextCursor, $result2->nextCursor);
        $this->assertEquals($result1->resources, $result2->resources);
    }

    private function addResourcesToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $resource = new Resource(
                uri: "resource://test/resource_$i",
                name: "resource_$i",
                description: "Test resource $i"
            );
            // Use a simple callable as handler
            $this->registry->registerResource($resource, fn () => null);
        }
    }

    private function createListResourcesRequest(?string $cursor = null): ListResourcesRequest
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 'test-request-id',
            'method' => 'resources/list',
        ];

        if (null !== $cursor) {
            $data['params'] = ['cursor' => $cursor];
        }

        return ListResourcesRequest::fromArray($data);
    }
}
