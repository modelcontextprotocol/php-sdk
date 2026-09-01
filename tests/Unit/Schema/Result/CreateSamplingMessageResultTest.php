<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use PHPUnit\Framework\TestCase;

final class CreateSamplingMessageResultTest extends TestCase
{
    public function testArrayContentAndKnownStopReasonAreHydrated(): void
    {
        $result = CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Checking weather.'],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'weather', 'input' => ['city' => 'Paris']],
            ],
            'model' => 'test-model',
            'stopReason' => 'toolUse',
            '_meta' => ['traceId' => 'trace-1'],
        ]);

        $this->assertInstanceOf(TextContent::class, $result->getContentBlocks()[0]);
        $this->assertInstanceOf(ToolUseContent::class, $result->getContentBlocks()[1]);
        $this->assertSame('toolUse', $result->stopReason);
        $this->assertSame('toolUse', $result->jsonSerialize()['stopReason'] ?? null);
        $this->assertSame(['traceId' => 'trace-1'], $result->jsonSerialize()['_meta'] ?? null);
    }

    public function testProviderSpecificStopReasonIsPreserved(): void
    {
        $result = CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => ['type' => 'text', 'text' => 'Done'],
            'model' => 'test-model',
            'stopReason' => 'provider-specific',
        ]);

        $this->assertSame('provider-specific', $result->stopReason);
        $this->assertSame('provider-specific', $result->jsonSerialize()['stopReason'] ?? null);
    }

    public function testKnownStopReasonStaysAString(): void
    {
        $result = CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => ['type' => 'text', 'text' => 'Done'],
            'model' => 'test-model',
            'stopReason' => 'endTurn',
        ]);

        $this->assertSame('endTurn', $result->stopReason);
    }

    public function testSingleContentBlockKeepsItsShape(): void
    {
        $result = CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => ['type' => 'text', 'text' => 'Done'],
            'model' => 'test-model',
        ]);

        $this->assertInstanceOf(TextContent::class, $result->content);
        $this->assertCount(1, $result->getContentBlocks());
        $this->assertSame('{"type":"text","text":"Done"}', json_encode($result->jsonSerialize()['content']));
    }

    public function testFilteredContentStillSerializesAsAnArray(): void
    {
        $blocks = [new TextContent('thinking'), new ToolUseContent('call-1', 'weather', [])];

        // array_filter() preserves keys, so this list starts at index 1.
        $toolUses = array_filter($blocks, static fn ($block): bool => $block instanceof ToolUseContent);
        $result = new CreateSamplingMessageResult(Role::Assistant, $toolUses, 'test-model');

        $this->assertSame(
            '[{"type":"tool_use","id":"call-1","name":"weather","input":{}}]',
            json_encode($result->jsonSerialize()['content']),
        );
    }

    public function testNonAssistantRoleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CreateSamplingMessageResult role must be "assistant".');

        CreateSamplingMessageResult::fromArray([
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => 'Done'],
            'model' => 'test-model',
        ]);
    }

    public function testEmptyContentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => [],
            'model' => 'test-model',
        ]);
    }

    public function testToolResultContentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateSamplingMessageResult::fromArray([
            'role' => 'assistant',
            'content' => ['type' => 'tool_result', 'toolUseId' => 'call-1', 'content' => []],
            'model' => 'test-model',
        ]);
    }
}
