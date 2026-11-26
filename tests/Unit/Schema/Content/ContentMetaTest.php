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

use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentMetaTest extends TestCase
{
    private const META = ['com.example/trace' => 'abc-123'];

    /**
     * @param class-string<TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLink> $class
     * @param array<string, mixed>                                                              $data
     */
    #[DataProvider('provideContentBlocks')]
    public function testFromArrayReadsMeta(string $class, array $data): void
    {
        $content = $class::fromArray($data + ['_meta' => self::META]);

        $this->assertSame(self::META, $content->meta);
    }

    /**
     * @param class-string<TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLink> $class
     * @param array<string, mixed>                                                              $data
     */
    #[DataProvider('provideContentBlocks')]
    public function testMetaIsNullWhenAbsent(string $class, array $data): void
    {
        $content = $class::fromArray($data);

        $this->assertNull($content->meta);
        $this->assertArrayNotHasKey('_meta', $content->jsonSerialize());
    }

    /**
     * @param class-string<TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLink> $class
     * @param array<string, mixed>                                                              $data
     */
    #[DataProvider('provideContentBlocks')]
    public function testMetaRoundTrips(string $class, array $data): void
    {
        $content = $class::fromArray($data + ['_meta' => self::META]);

        $encoded = json_decode(json_encode($content, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(self::META, $encoded['_meta']);
        $this->assertEquals($content, $class::fromArray($encoded));
    }

    /**
     * @param class-string<TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLink> $class
     * @param array<string, mixed>                                                              $data
     */
    #[DataProvider('provideContentBlocks')]
    public function testFromArrayRejectsMetaThatIsNotAnObject(string $class, array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "_meta"');

        $class::fromArray($data + ['_meta' => 'abc-123']);
    }

    /**
     * @return iterable<string, array{class-string<Content>, array<string, mixed>}>
     */
    public static function provideContentBlocks(): iterable
    {
        yield 'text' => [TextContent::class, ['type' => 'text', 'text' => 'Hello']];
        yield 'image' => [ImageContent::class, ['type' => 'image', 'data' => base64_encode('png'), 'mimeType' => 'image/png']];
        yield 'audio' => [AudioContent::class, ['type' => 'audio', 'data' => base64_encode('wav'), 'mimeType' => 'audio/wav']];
        yield 'embedded resource' => [EmbeddedResource::class, ['type' => 'resource', 'resource' => ['uri' => 'file:///notes.txt', 'mimeType' => 'text/plain', 'text' => 'Notes']]];
        yield 'resource link' => [ResourceLink::class, ['type' => 'resource_link', 'uri' => 'file:///notes.txt', 'name' => 'notes.txt']];
    }

    public function testConstructorsAcceptMeta(): void
    {
        $this->assertSame(self::META, (new TextContent('Hello', meta: self::META))->jsonSerialize()['_meta'] ?? null);
        $this->assertSame(self::META, (new ImageContent(base64_encode('png'), 'image/png', meta: self::META))->jsonSerialize()['_meta'] ?? null);
        $this->assertSame(self::META, (new AudioContent(base64_encode('wav'), 'audio/wav', meta: self::META))->jsonSerialize()['_meta'] ?? null);
        $this->assertSame(self::META, (new EmbeddedResource(new TextResourceContents('file:///notes.txt', 'text/plain', 'Notes'), meta: self::META))->jsonSerialize()['_meta'] ?? null);
    }

    /**
     * The `_meta` of the content block and the one of its resource are distinct fields.
     */
    public function testEmbeddedResourceKeepsBlockMetaApartFromResourceMeta(): void
    {
        $content = EmbeddedResource::fromArray([
            'type' => 'resource',
            'resource' => ['uri' => 'file:///notes.txt', 'text' => 'Notes', '_meta' => ['com.example/source' => 'disk']],
            '_meta' => self::META,
        ]);

        $this->assertSame(self::META, $content->meta);
        $this->assertSame(['com.example/source' => 'disk'], $content->resource->meta);
    }
}
