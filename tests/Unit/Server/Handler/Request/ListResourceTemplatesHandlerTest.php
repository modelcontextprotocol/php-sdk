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

use Mcp\Capability\Registry;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Server\Handler\Request\ListResourceTemplatesHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

class ListResourceTemplatesHandlerTest extends TestCase
{
    private Registry $registry;
    private ListResourceTemplatesHandler $handler;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListResourceTemplatesHandler($this->registry, pageSize: 3);
        $this->session = new Session(new InMemorySessionStore());
    }

    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(3, $result->resourceTemplates);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('resource://{test}/resource_0', $result->resourceTemplates[0]->uriTemplate);
        $this->assertEquals('resource://{test}/resource_1', $result->resourceTemplates[1]->uriTemplate);
        $this->assertEquals('resource://{test}/resource_2', $result->resourceTemplates[2]->uriTemplate);
    }

    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListResourceTemplatesResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(3, $result->resourceTemplates);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('resource://{test}/resource_3', $result->resourceTemplates[0]->uriTemplate);
        $this->assertEquals('resource://{test}/resource_4', $result->resourceTemplates[1]->uriTemplate);
        $this->assertEquals('resource://{test}/resource_5', $result->resourceTemplates[2]->uriTemplate);
    }

    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addResourcesToRegistry(5);
        $firstPageRequest = $this->createListResourcesRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListResourceTemplatesResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListResourcesRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(2, $result->resourceTemplates);
        $this->assertNull($result->nextCursor);

        $this->assertEquals('resource://{test}/resource_3', $result->resourceTemplates[0]->uriTemplate);
        $this->assertEquals('resource://{test}/resource_4', $result->resourceTemplates[1]->uriTemplate);
    }

    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListResourcesRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(0, $result->resourceTemplates);
        $this->assertNull($result->nextCursor);
    }

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

    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addResourcesToRegistry(6);
        $exactBoundaryCursor = base64_encode('6');
        $request = $this->createListResourcesRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(0, $result->resourceTemplates);
        $this->assertNull($result->nextCursor);
    }

    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addResourcesToRegistry(10);

        // Act
        $request = $this->createListResourcesRequest();
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListResourceTemplatesResult $result1 */
        $result1 = $response1->result;
        /** @var ListResourceTemplatesResult $result2 */
        $result2 = $response2->result;
        $this->assertEquals($result1->nextCursor, $result2->nextCursor);
        $this->assertEquals($result1->resourceTemplates, $result2->resourceTemplates);
    }

    private function addResourcesToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $resourceTemplate = new ResourceTemplate(
                uriTemplate: "resource://{test}/resource_$i",
                name: "resource_$i",
                description: "Test resource $i"
            );
            // Use a simple callable as handler
            $this->registry->registerResourceTemplate($resourceTemplate, fn () => null);
        }
    }

    private function createListResourcesRequest(?string $cursor = null): ListResourceTemplatesRequest
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 'test-request-id',
            'method' => 'resources/list',
        ];

        if (null !== $cursor) {
            $data['params'] = ['cursor' => $cursor];
        }

        return ListResourceTemplatesRequest::fromArray($data);
    }
}
