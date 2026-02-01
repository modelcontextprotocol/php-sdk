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
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Server\Handler\Request\ListPromptsHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ListPromptsHandlerTest extends TestCase
{
    private Registry $registry;
    private ListPromptsHandler $handler;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->handler = new ListPromptsHandler($this->registry, pageSize: 3); // Use small page size for testing
        $this->session = new Session(new InMemorySessionStore());
    }

    #[TestDox('Returns first page when no cursor provided')]
    public function testReturnsFirstPageWhenNoCursorProvided(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $request = $this->createListPromptsRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(3, $result->prompts);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('prompt_0', $result->prompts[0]->name);
        $this->assertEquals('prompt_1', $result->prompts[1]->name);
        $this->assertEquals('prompt_2', $result->prompts[2]->name);
    }

    #[TestDox('Returns paginated prompts with cursor')]
    public function testReturnsPaginatedPromptsWithCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);
        $request = $this->createListPromptsRequest(cursor: null);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(3, $result->prompts);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('prompt_0', $result->prompts[0]->name);
        $this->assertEquals('prompt_1', $result->prompts[1]->name);
        $this->assertEquals('prompt_2', $result->prompts[2]->name);
    }

    #[TestDox('Returns second page with cursor')]
    public function testReturnsSecondPageWithCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);
        $firstPageRequest = $this->createListPromptsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListPromptsResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListPromptsRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(3, $result->prompts);
        $this->assertNotNull($result->nextCursor);

        $this->assertEquals('prompt_3', $result->prompts[0]->name);
        $this->assertEquals('prompt_4', $result->prompts[1]->name);
        $this->assertEquals('prompt_5', $result->prompts[2]->name);
    }

    #[TestDox('Returns last page with null cursor')]
    public function testReturnsLastPageWithNullCursor(): void
    {
        // Arrange
        $this->addPromptsToRegistry(5);
        $firstPageRequest = $this->createListPromptsRequest();
        $firstPageResponse = $this->handler->handle($firstPageRequest, $this->session);

        /** @var ListPromptsResult $firstPageResult */
        $firstPageResult = $firstPageResponse->result;
        $secondPageRequest = $this->createListPromptsRequest(cursor: $firstPageResult->nextCursor);

        // Act
        $response = $this->handler->handle($secondPageRequest, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(2, $result->prompts);
        $this->assertNull($result->nextCursor);

        $this->assertEquals('prompt_3', $result->prompts[0]->name);
        $this->assertEquals('prompt_4', $result->prompts[1]->name);
    }

    #[TestDox('Handles empty registry')]
    public function testHandlesEmptyRegistry(): void
    {
        // Arrange
        $request = $this->createListPromptsRequest();

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(0, $result->prompts);
        $this->assertNull($result->nextCursor);
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
        $this->handler->handle($request, $this->session);
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
        $this->handler->handle($request, $this->session);
    }

    #[TestDox('Handles cursor at exact boundary')]
    public function testHandlesCursorAtExactBoundary(): void
    {
        // Arrange
        $this->addPromptsToRegistry(6);
        $exactBoundaryCursor = base64_encode('6'); // Exactly at the end
        $request = $this->createListPromptsRequest(cursor: $exactBoundaryCursor);

        // Act
        $response = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListPromptsResult $result */
        $result = $response->result;
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(0, $result->prompts);
        $this->assertNull($result->nextCursor);
    }

    #[TestDox('Maintains stable cursors across calls')]
    public function testMaintainsStableCursorsAcrossCalls(): void
    {
        // Arrange
        $this->addPromptsToRegistry(10);

        // Act
        $request = $this->createListPromptsRequest();
        $response1 = $this->handler->handle($request, $this->session);
        $response2 = $this->handler->handle($request, $this->session);

        // Assert
        /** @var ListPromptsResult $result1 */
        $result1 = $response1->result;
        /** @var ListPromptsResult $result2 */
        $result2 = $response2->result;
        $this->assertEquals($result1->nextCursor, $result2->nextCursor);
        $this->assertEquals($result1->prompts, $result2->prompts);
    }

    private function addPromptsToRegistry(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $prompt = new Prompt(
                name: "prompt_$i",
                description: "Test prompt $i"
            );

            $this->registry->registerPrompt($prompt, static fn () => null);
        }
    }

    private function createListPromptsRequest(?string $cursor = null): ListPromptsRequest
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 'test-request-id',
            'method' => 'prompts/list',
        ];

        if (null !== $cursor) {
            $data['params'] = ['cursor' => $cursor];
        }

        return ListPromptsRequest::fromArray($data);
    }
}
