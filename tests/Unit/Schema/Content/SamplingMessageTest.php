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

final class SamplingMessageTest extends TestCase
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

        $this->assertEquals($assistant, SamplingMessage::fromArray(json_decode(json_encode($assistant), true)));
        $this->assertEquals($user, SamplingMessage::fromArray(json_decode(json_encode($user), true)));
    }

    public function testSingleContentBlockKeepsItsShape(): void
    {
        $message = SamplingMessage::fromArray(['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]);

        $this->assertInstanceOf(TextContent::class, $message->content);
        $this->assertSame('{"role":"user","content":{"type":"text","text":"hi"}}', json_encode($message));
        $this->assertCount(1, $message->getContentBlocks());
    }

    public function testSingleElementListKeepsItsShape(): void
    {
        $message = SamplingMessage::fromArray(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]);

        $this->assertIsArray($message->content);
        $this->assertSame('{"role":"user","content":[{"type":"text","text":"hi"}]}', json_encode($message));
        $this->assertCount(1, $message->getContentBlocks());
    }

    /**
     * The tool-flow rules span the whole message list, so a single message is never
     * rejected for carrying the "wrong" block — see CreateSamplingMessageRequestTest.
     */
    public function testToolBlocksAreAcceptedRegardlessOfRole(): void
    {
        $message = new SamplingMessage(Role::User, new ToolUseContent('call-1', 'weather', []));

        $this->assertInstanceOf(ToolUseContent::class, $message->content);
    }

    public function testEmptyContentListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SamplingMessage(Role::User, []);
    }

    public function testEmptyContentListIsRejectedWhenHydrating(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplingMessage::fromArray(['role' => 'user', 'content' => []]);
    }

    public function testUnknownContentTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplingMessage::fromArray(['role' => 'user', 'content' => ['type' => 'nope']]);
    }

    public function testUnknownRoleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore argument.type */
        SamplingMessage::fromArray(['role' => 'system', 'content' => ['type' => 'text', 'text' => 'hi']]);
    }
}
