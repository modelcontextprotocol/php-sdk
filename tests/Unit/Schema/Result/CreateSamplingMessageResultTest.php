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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolUseContent;
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

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertInstanceOf(ToolUseContent::class, $result->content[1]);
        $this->assertSame('toolUse', $result->stopReason);
        $this->assertSame('toolUse', $result->jsonSerialize()['stopReason']);
        $this->assertSame(['traceId' => 'trace-1'], $result->jsonSerialize()['_meta']);
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
        $this->assertSame('provider-specific', $result->jsonSerialize()['stopReason']);
    }
}
