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
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\RequestHandler\ListToolsHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ListToolsHandlerTest extends TestCase
{
    private Registry $registry;
    private ListToolsHandler $handler;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListToolsHandler($this->registry, pageSize: 3); // Use small page size for testing
        $this->session = new Session(new InMemorySessionStore());
    }

    #[TestDox('Returns first page when no cursor provided')]
    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addToolsToRegistry(5);
        $request = $this->createListToolsRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(3, $result->tools);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('tool_0', $result->tools[0]->name);
        $this->assertEquals('tool_1', $result->tools[1]->name);
        $this->assertEquals('tool_2', $result->tools[2]->name);
    }

    #[TestDox('Returns paginated tools with cursor')]
    public function testReturnsPaginatedToolsWithCursor(): void
    {
        // Arrange
        $this->addToolsToRegistry(10);
        $request = $this->createListToolsRequest(cursor: null);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(3, $result->tools);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('tool_0', $result->tools[0]->name);
        $this->assertEquals('tool_1', $result->tools[1]->name);
        $this->assertEquals('tool_2', $result->tools[2]->name);
    }

    #[TestDox('Returns second page with cursor')]
    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addToolsToRegistry(10);
        $firstPageRequest = $this->createListToolsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListToolsResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListToolsRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(3, $result->tools);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('tool_3', $result->tools[0]->name);
        $this->assertEquals('tool_4', $result->tools[1]->name);
        $this->assertEquals('tool_5', $result->tools[2]->name);
    }

    #[TestDox('Returns last page with null cursor')]
    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addToolsToRegistry(5);
        $firstPageRequest = $this->createListToolsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListToolsResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListToolsRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(2, $result->tools);
        $this->assertNull($result->nextCursor);

        $this->assertEquals('tool_3', $result->tools[0]->name);
        $this->assertEquals('tool_4', $result->tools[1]->name);
    }

    #[TestDox('Returns all tools when count is less than page size')]
    public function testReturnsAllToolsWhenCountIsLessThanPageSize(): void
    {
        // Arrange
        $this->addToolsToRegistry(2); // Less than page size 3
        $request = $this->createListToolsRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(2, $result->tools);
        $this->assertNull($result->nextCursor);

        $this->assertEquals('tool_0', $result->tools[0]->name);
        $this->assertEquals('tool_1', $result->tools[1]->name);
    }

    #[TestDox('Handles empty registry')]
    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListToolsRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(0, $result->tools);
        $this->assertNull($result->nextCursor);
    }

    #[TestDox('Throws exception for invalid cursor')]
    public function testThrowsExceptionForInvalidCursor(): void
    {
        // Arrange
        $this->addToolsToRegistry(5);
        $request = $this->createListToolsRequest(cursor: 'invalid-cursor');

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request, $this->session);
    }

    #[TestDox('Throws exception for cursor beyond bounds')]
    public function testThrowsExceptionForCursorBeyondBounds(): void
    {
        // Arrange
        $this->addToolsToRegistry(5);
        $outOfBoundsCursor = base64_encode('100');
        $request = $this->createListToolsRequest(cursor: $outOfBoundsCursor);

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request, $this->session);
    }

    #[TestDox('Handles cursor at exact boundary')]
    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addToolsToRegistry(6);
        $exactBoundaryCursor = base64_encode('6');
        $request = $this->createListToolsRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(0, $result->tools);
        $this->assertNull($result->nextCursor);
    }

    #[TestDox('Maintains stable cursors across calls')]
    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addToolsToRegistry(10);

        // Act
        $request = $this->createListToolsRequest();
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result1 */
        $result1 = $response1->result;
        /** @var ListToolsResult $result2 */
        $result2 = $response2->result;
        $this->assertEquals($result1->nextCursor, $result2->nextCursor);
        $this->assertEquals($result1->tools, $result2->tools);
    }

    #[TestDox('Uses custom page size when provided')]
    public function testUsesCustomPageSizeWhenProvided(): void
    {
        // Arrange
        $customPageSize = 5;
        $customHandler = new ListToolsHandler($this->registry, pageSize: $customPageSize);
        $this->addToolsToRegistry(10);
        $request = $this->createListToolsRequest();

        // Act
        $response = $customHandler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount($customPageSize, $result->tools);
        $this->assertNotNull($result->nextCursor);
    }

    #[TestDox('Different page sizes produce different pagination results')]
    public function testDifferentPageSizesProduceDifferentPaginationResults(): void
    {
        // Arrange
        $this->addToolsToRegistry(10);
        $smallPageHandler = new ListToolsHandler($this->registry, pageSize: 2);
        $largePageHandler = new ListToolsHandler($this->registry, pageSize: 7);
        $request = $this->createListToolsRequest();

        // Act
        $smallPageResponse = $smallPageHandler->handle($request, $this->session);
        $largePageResponse = $largePageHandler->handle($request, $this->session);

        // Assert
        /** @var ListToolsResult $smallResult */
        $smallResult = $smallPageResponse->result;
        /** @var ListToolsResult $largeResult */
        $largeResult = $largePageResponse->result;

        $this->assertCount(2, $smallResult->tools);
        $this->assertCount(7, $largeResult->tools);
        $this->assertNotNull($smallResult->nextCursor);
        $this->assertNotNull($largeResult->nextCursor);
    }

    private function addToolsToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $tool = new Tool(
                name: "tool_$i",
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                description: "Test tool $i",
                annotations: null
            );

            $this->registry->registerTool($tool, fn () => null);
        }
    }

    private function createListToolsRequest(?string $cursor = null): ListToolsRequest
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 'test-request-id',
            'method' => 'tools/list',
        ];

        if (null !== $cursor) {
            $data['params'] = ['cursor' => $cursor];
        }

        return ListToolsRequest::fromArray($data);
    }
}
