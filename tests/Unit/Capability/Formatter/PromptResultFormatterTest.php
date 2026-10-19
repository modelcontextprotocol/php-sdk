<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Formatter;

use Mcp\Capability\Formatter\PromptResultFormatter;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

class PromptResultFormatterTest extends TestCase
{
    public function testFormatPromptMessage(): void
    {
        $message = new PromptMessage(Role::User, new TextContent('hello'));
        $result = (new PromptResultFormatter())->format($message);
        $this->assertCount(1, $result);
        $this->assertSame($message, $result[0]);
    }

    public function testFormatPromptMessageWithResourceLinkContent(): void
    {
        $message = new PromptMessage(Role::User, new ResourceLink('file:///a.png', 'a.png'));
        $result = (new PromptResultFormatter())->format($message);
        $this->assertCount(1, $result);
        $this->assertSame($message, $result[0]);
    }

    public function testFormatRoleContentArrayWithResourceLinkContent(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => ['type' => 'resource_link', 'uri' => 'file:///a.png', 'name' => 'a.png'],
            ],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame(Role::User, $result[0]->role);
        $this->assertInstanceOf(ResourceLink::class, $result[0]->content);
        $this->assertSame('file:///a.png', $result[0]->content->uri);
        $this->assertSame('a.png', $result[0]->content->name);
    }

    public function testFormatTypedResourceLinkContentPreservesOptionalFields(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource_link',
                    'uri' => 'file:///a.png',
                    'name' => 'a.png',
                    'title' => 'A picture',
                    'description' => 'The first picture',
                    'mimeType' => 'image/png',
                    'size' => 1024,
                    'annotations' => ['audience' => ['user'], 'priority' => 0.5],
                    '_meta' => ['origin' => 'test'],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(ResourceLink::class, $content);
        $this->assertSame('file:///a.png', $content->uri);
        $this->assertSame('a.png', $content->name);
        $this->assertSame('A picture', $content->title);
        $this->assertSame('The first picture', $content->description);
        $this->assertSame('image/png', $content->mimeType);
        $this->assertSame(1024, $content->size);
        $this->assertNotNull($content->annotations);
        $this->assertSame([Role::User], $content->annotations->audience);
        $this->assertSame(0.5, $content->annotations->priority);
        $this->assertSame(['origin' => 'test'], $content->meta);
    }

    public function testFormatUserAssistantShorthand(): void
    {
        $result = (new PromptResultFormatter())->format([
            'user' => 'Hello',
            'assistant' => 'Hi there',
        ]);
        $this->assertCount(2, $result);
        $this->assertSame(Role::User, $result[0]->role);
        $this->assertSame(Role::Assistant, $result[1]->role);
    }

    public function testFormatRoleContentArray(): void
    {
        $result = (new PromptResultFormatter())->format([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame(Role::User, $result[0]->role);
    }

    public function testFormatTypedTextContentPreservesAnnotations(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => 'Hello',
                    'annotations' => ['audience' => ['user'], 'priority' => 0.5],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(TextContent::class, $content);
        $this->assertSame('Hello', $content->text);
        $this->assertNotNull($content->annotations);
        $this->assertSame([Role::User], $content->annotations->audience);
        $this->assertSame(0.5, $content->annotations->priority);
    }

    public function testFormatTypedImageContentPreservesAnnotations(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'image',
                    'data' => base64_encode('binary'),
                    'mimeType' => 'image/png',
                    'annotations' => ['audience' => ['assistant']],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(ImageContent::class, $content);
        $this->assertSame('image/png', $content->mimeType);
        $this->assertNotNull($content->annotations);
        $this->assertSame([Role::Assistant], $content->annotations->audience);
    }

    public function testFormatTypedAudioContentPreservesAnnotations(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'audio',
                    'data' => base64_encode('binary'),
                    'mimeType' => 'audio/mpeg',
                    'annotations' => ['priority' => 1.0],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(AudioContent::class, $content);
        $this->assertNotNull($content->annotations);
        $this->assertSame(1.0, $content->annotations->priority);
    }

    public function testFormatTypedResourceContentPreservesOptionalFields(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource',
                    'resource' => [
                        'uri' => 'file://data.json',
                        'mimeType' => 'application/json',
                        'text' => '{"key": "value"}',
                        '_meta' => ['origin' => 'test'],
                    ],
                    'annotations' => ['audience' => ['user']],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(EmbeddedResource::class, $content);
        $this->assertSame('application/json', $content->resource->mimeType);
        $this->assertSame(['origin' => 'test'], $content->resource->meta);
        $this->assertNotNull($content->annotations);
        $this->assertSame([Role::User], $content->annotations->audience);
    }

    public function testFormatTypedTextResourceContentDefaultsMimeType(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource',
                    'resource' => ['uri' => 'file://a.txt', 'text' => 'plain'],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(EmbeddedResource::class, $content);
        $this->assertSame('text/plain', $content->resource->mimeType);
    }

    public function testFormatTypedBlobResourceContentDefaultsMimeType(): void
    {
        $result = (new PromptResultFormatter())->format([
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource',
                    'resource' => ['uri' => 'file://a.bin', 'blob' => base64_encode('binary')],
                ],
            ],
        ]);

        $content = $result[0]->content;
        $this->assertInstanceOf(EmbeddedResource::class, $content);
        $this->assertSame('application/octet-stream', $content->resource->mimeType);
    }

    public function testFormatTypedContentRejectsInvalidDataWithIndexContext(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid 'text' content at index 0");

        (new PromptResultFormatter())->format([
            ['role' => 'user', 'content' => ['type' => 'text']],
        ]);
    }

    public function testFormatTypedContentRejectsUnknownType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid content type 'video' at index 0.");

        (new PromptResultFormatter())->format([
            ['role' => 'user', 'content' => ['type' => 'video']],
        ]);
    }
}
