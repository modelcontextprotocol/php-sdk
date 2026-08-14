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
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

final class PromptMessageTest extends TestCase
{
    public function testFromArrayDeserializesResourceLinkContent(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => [
                'type' => 'resource_link',
                'uri' => 'file:///project/src/main.rs',
                'name' => 'main.rs',
            ],
        ]);

        $this->assertSame(Role::User, $message->role);
        $this->assertInstanceOf(ResourceLink::class, $message->content);
        $this->assertSame('file:///project/src/main.rs', $message->content->uri);
        $this->assertSame('main.rs', $message->content->name);
    }

    public function testJsonSerializeIncludesResourceLinkContent(): void
    {
        $message = new PromptMessage(Role::Assistant, new ResourceLink('file:///a.png', 'a.png'));

        $this->assertSame([
            'role' => 'assistant',
            'content' => [
                'type' => 'resource_link',
                'uri' => 'file:///a.png',
                'name' => 'a.png',
            ],
        ], json_decode(json_encode($message), true));
    }

    public function testRoundTripWithResourceLink(): void
    {
        $original = new PromptMessage(Role::User, new ResourceLink('file:///a.png', 'a.png', mimeType: 'image/png'));

        $decoded = json_decode(json_encode($original), true);
        $rehydrated = PromptMessage::fromArray($decoded);

        $this->assertSame(Role::User, $rehydrated->role);
        $this->assertInstanceOf(ResourceLink::class, $rehydrated->content);
        $this->assertSame('file:///a.png', $rehydrated->content->uri);
        $this->assertSame('image/png', $rehydrated->content->mimeType);
    }

    public function testFromArrayRejectsUnknownContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore argument.type */
        PromptMessage::fromArray([
            'role' => 'user',
            'content' => ['type' => 'not-a-real-type'],
        ]);
    }

    public function testConstructorAcceptsResourceLinkContent(): void
    {
        $link = new ResourceLink('file:///a.png', 'a.png');
        $message = new PromptMessage(Role::User, $link);

        $this->assertSame($link, $message->content);
    }
}
