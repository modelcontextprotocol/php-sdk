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
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

final class ToolResultContentTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $content = ToolResultContent::fromArray([
            'type' => 'tool_result',
            'toolUseId' => 'call-1',
            'content' => [['type' => 'text', 'text' => '21 C']],
            'structuredContent' => ['temperature' => 21],
            'isError' => true,
            '_meta' => ['provider' => 'test'],
        ]);

        $this->assertSame('tool_result', $content->type);
        $this->assertSame('call-1', $content->toolUseId);
        $this->assertSame(['temperature' => 21], $content->structuredContent);
        $this->assertTrue($content->isError);
        $this->assertSame(['provider' => 'test'], $content->meta);

        $textContent = $content->content[0];
        $this->assertInstanceOf(TextContent::class, $textContent);
        $this->assertSame('21 C', $textContent->text);

        $restored = ToolResultContent::fromArray(json_decode(json_encode($content), true));
        $this->assertEquals($content, $restored);
    }

    public function testIsErrorIsOmittedWhenFalse(): void
    {
        $content = new ToolResultContent('call-1', [new TextContent('ok')]);

        $serialized = $content->jsonSerialize();

        $this->assertArrayNotHasKey('isError', $serialized);
        $this->assertArrayNotHasKey('structuredContent', $serialized);
        $this->assertArrayNotHasKey('_meta', $serialized);
        $this->assertFalse(ToolResultContent::fromArray(json_decode(json_encode($content), true))->isError);
    }

    public function testAcceptsEveryCallToolResultContentBlock(): void
    {
        $content = ToolResultContent::fromArray([
            'toolUseId' => 'call-1',
            'content' => [
                ['type' => 'text', 'text' => 'a'],
                ['type' => 'image', 'data' => base64_encode('img'), 'mimeType' => 'image/png'],
                ['type' => 'audio', 'data' => base64_encode('snd'), 'mimeType' => 'audio/wav'],
                ['type' => 'resource_link', 'uri' => 'file:///report.txt', 'name' => 'report'],
                ['type' => 'resource', 'resource' => ['uri' => 'file:///a.txt', 'mimeType' => 'text/plain', 'text' => 'a']],
            ],
        ]);

        $this->assertInstanceOf(ResourceLink::class, $content->content[3]);
        $this->assertInstanceOf(EmbeddedResource::class, $content->content[4]);
    }

    public function testRejectsNonStandardContentBlocks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore argument.type */
        new ToolResultContent('call-1', [
            new SamplingMessage(Role::User, new TextContent('not a tool result content block')),
        ]);
    }

    public function testRejectsUnsupportedContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolResultContent::fromArray([
            'toolUseId' => 'call-1',
            'content' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'y', 'input' => []]],
        ]);
    }

    public function testRejectsMissingToolUseId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolResultContent::fromArray(['content' => []]);
    }
}
