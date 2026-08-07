<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Content;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

final class SamplingToolContentTest extends TestCase
{
    public function testToolLoopMessagesRoundTrip(): void
    {
        $assistant = SamplingMessage::fromArray([
            'role' => 'assistant',
            '_meta' => ['provider' => 'test'],
            'content' => [
                ['type' => 'text', 'text' => 'I will check.'],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'weather', 'input' => ['city' => 'Paris']],
            ],
        ]);
        $user = SamplingMessage::fromArray([
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'toolUseId' => 'call-1',
                'content' => [['type' => 'text', 'text' => '21 C']],
                'structuredContent' => ['temperature' => 21],
            ]],
        ]);

        $this->assertInstanceOf(ToolUseContent::class, $assistant->content[1]);
        $this->assertSame(['provider' => 'test'], $assistant->meta);
        $this->assertSame(['provider' => 'test'], $assistant->jsonSerialize()['_meta']);
        $this->assertInstanceOf(ToolResultContent::class, $user->content[0]);
        $this->assertSame(['temperature' => 21], $user->content[0]->structuredContent);
        $textContent = $user->content[0]->content[0];
        $this->assertInstanceOf(TextContent::class, $textContent);
        $this->assertSame('21 C', $textContent->text);
    }

    public function testToolUseIsRejectedInUserMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SamplingMessage(Role::User, new ToolUseContent('call-1', 'weather', []));
    }

    public function testToolResultCannotBeMixedWithOtherContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SamplingMessage(Role::User, [
            new ToolResultContent('call-1', [new TextContent('done')]),
            new TextContent('extra'),
        ]);
    }
}
