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
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Server\RequestHandler\ListPromptsHandler;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ListPromptsHandlerTest extends TestCase
{
    private Registry $registry;
    private ListPromptsHandler $handler;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListPromptsHandler($this->registry, pageSize: 3); // Use small page size for testing
    }

    #[TestDox('Returns first page when no cursor provided')]
    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $request = $this->createListPromptsRequest();

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(3, $response->result->prompts);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('prompt_0', $response->result->prompts[0]->name);
        $this->assertEquals('prompt_1', $response->result->prompts[1]->name);
        $this->assertEquals('prompt_2', $response->result->prompts[2]->name);
    }

    #[TestDox('Returns paginated prompts with cursor')]
    public function testReturnsPaginatedPromptsWithCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);
        $request = $this->createListPromptsRequest(cursor: null);

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(3, $response->result->prompts);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('prompt_0', $response->result->prompts[0]->name);
        $this->assertEquals('prompt_1', $response->result->prompts[1]->name);
        $this->assertEquals('prompt_2', $response->result->prompts[2]->name);
    }

    #[TestDox('Returns second page with cursor')]
    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);
        $firstPageRequest = $this->createListPromptsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest);
        
        $secondPageRequest = $this->createListPromptsRequest(cursor: $firstPageResponse->result->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(3, $response->result->prompts);
        $this->assertNotNull($response->result->nextCursor);

        $this->assertEquals('prompt_3', $response->result->prompts[0]->name);
        $this->assertEquals('prompt_4', $response->result->prompts[1]->name);
        $this->assertEquals('prompt_5', $response->result->prompts[2]->name);
    }

    #[TestDox('Returns last page with null cursor')]
    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $firstPageRequest = $this->createListPromptsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest);
        
        $secondPageRequest = $this->createListPromptsRequest(cursor: $firstPageResponse->result->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(2, $response->result->prompts);
        $this->assertNull($response->result->nextCursor);

        $this->assertEquals('prompt_3', $response->result->prompts[0]->name);
        $this->assertEquals('prompt_4', $response->result->prompts[1]->name);
    }

    #[TestDox('Handles empty registry')]
    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListPromptsRequest();

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(0, $response->result->prompts);
        $this->assertNull($response->result->nextCursor);
    }

    #[TestDox('Throws exception for invalid cursor')]
    public function testThrowsExceptionForInvalidCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $request = $this->createListPromptsRequest(cursor: 'invalid-cursor');

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request);
    }

    #[TestDox('Throws exception for cursor beyond bounds')]
    public function testThrowsExceptionForCursorBeyondBounds(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $outOfBoundsCursor = base64_encode('1000');
        $request = $this->createListPromptsRequest(cursor: $outOfBoundsCursor);

        // Assert
        $this->expectException(InvalidCursorException::class);

        // Act
        $this->handler->handle($request);
    }

    #[TestDox('Handles cursor at exact boundary')]
    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addPromptsToRegistry(6);
        $exactBoundaryCursor = base64_encode('6');
        $request = $this->createListPromptsRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request);

        // Assert
        $this->assertInstanceOf(ListPromptsResult::class, $response->result);
        $this->assertCount(0, $response->result->prompts);
        $this->assertNull($response->result->nextCursor);
    }

    #[TestDox('Maintains stable cursors across calls')]
    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);
        
        // Act
        $request = $this->createListPromptsRequest();
        $response1 = $this->handler->handle($request);
        $response2 = $this->handler->handle($request);

        // Assert
        $this->assertEquals($response1->result->nextCursor, $response2->result->nextCursor);
        $this->assertEquals($response1->result->prompts, $response2->result->prompts);
    }

    private function addPromptsToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $prompt = new Prompt(
                name: "prompt_$i",
                description: "Test prompt $i"
            );

            $this->registry->registerPrompt($prompt, fn() => null);
        }
    }

    private function createListPromptsRequest(?string $cursor = null): ListPromptsRequest
    {
        $listPromptsRequest = new ListPromptsRequest(cursor: $cursor);

        $reflection = new ReflectionClass($listPromptsRequest);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($listPromptsRequest, 'test-request-id');
        
        return $listPromptsRequest;
    }
}