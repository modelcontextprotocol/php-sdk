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
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\UndefinedValue;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CallToolResultTest extends TestCase
{
    public function testRuntimeAbsenceCanBePassedAlongsideMetadata(): void
    {
        $result = new CallToolResult([], false, UndefinedValue::Value, ['example' => 'metadata']);

        $this->assertFalse($result->hasStructuredContent());
        $this->assertSame(['content' => [], 'isError' => false, '_meta' => ['example' => 'metadata']], $result->jsonSerialize());
    }

    public function testAbsentAndExplicitNullRoundTripSeparately(): void
    {
        foreach ([false, true] as $present) {
            $wire = ['content' => [], 'isError' => false];
            if ($present) {
                $wire['structuredContent'] = null;
            }

            $result = CallToolResult::fromArray($wire);
            $this->assertSame($present, $result->hasStructuredContent());
            $this->assertSame($wire, json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));
        }
    }

    #[TestWith([false])]
    #[TestWith([0])]
    #[TestWith([''])]
    #[TestWith([[]])]
    public function testFalseyStructuredValuesRemainPresent(mixed $value): void
    {
        $result = new CallToolResult([], structuredContent: $value);
        $this->assertTrue($result->hasStructuredContent());
        $this->assertSame(['content' => [], 'isError' => false, 'structuredContent' => $value], $result->jsonSerialize());
    }

    public function testTextOnlyResultsStillOmitStructuredContent(): void
    {
        $content = [new TextContent('text only')];
        $meta = ['example' => 'metadata'];

        foreach ([
            new CallToolResult($content),
            new CallToolResult($content, meta: $meta),
            CallToolResult::success($content, $meta),
            CallToolResult::error($content, $meta),
            CallToolResult::fromArray(['content' => [['type' => 'text', 'text' => 'text only']]]),
        ] as $result) {
            $this->assertFalse($result->hasStructuredContent());
            $this->assertNull($result->structuredContent);
            $this->assertArrayNotHasKey('structuredContent', $result->jsonSerialize());
        }
    }

    public function testExplicitNullStructuredContentIsSerialized(): void
    {
        $result = new CallToolResult([new TextContent('null')], structuredContent: null);
        $wire = json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('structuredContent', $wire);
        $this->assertNull($wire['structuredContent']);
    }

    /**
     * @param array{int|string, int|string} $keys
     */
    #[TestWith([['first', 'second']])]
    #[TestWith([[1, 3]])]
    public function testContentSerializesAsListForNonListKeys(array $keys): void
    {
        $first = new TextContent('first');
        $second = new TextContent('second');

        $result = new CallToolResult([$keys[0] => $first, $keys[1] => $second]);

        $this->assertSame([$first, $second], $result->jsonSerialize()['content']);
    }

    public function testFromArrayDeserializesResourceLinkContent(): void
    {
        $result = CallToolResult::fromArray([
            'content' => [
                [
                    'type' => 'resource_link',
                    'uri' => 'file:///project/src/main.rs',
                    'name' => 'main.rs',
                    'mimeType' => 'text/x-rust',
                ],
            ],
            'isError' => false,
        ]);

        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(ResourceLink::class, $result->content[0]);
        $this->assertSame('file:///project/src/main.rs', $result->content[0]->uri);
        $this->assertSame('main.rs', $result->content[0]->name);
        $this->assertSame('text/x-rust', $result->content[0]->mimeType);
    }

    public function testFromArrayDeserializesMixedContentTypes(): void
    {
        $result = CallToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'search results'],
                ['type' => 'resource_link', 'uri' => 'file:///a.png', 'name' => 'a.png'],
                ['type' => 'resource_link', 'uri' => 'file:///b.png', 'name' => 'b.png'],
            ],
        ]);

        $this->assertCount(3, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertInstanceOf(ResourceLink::class, $result->content[1]);
        $this->assertInstanceOf(ResourceLink::class, $result->content[2]);
    }

    public function testFromArrayRejectsUnknownContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CallToolResult::fromArray([
            'content' => [
                ['type' => 'not-a-real-type'],
            ],
        ]);
    }

    public function testJsonSerializeIncludesResourceLinkContent(): void
    {
        $result = new CallToolResult([
            new ResourceLink('file:///project/src/main.rs', 'main.rs'),
        ]);

        $data = $result->jsonSerialize();

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => 'file:///project/src/main.rs',
            'name' => 'main.rs',
        ], $data['content'][0]->jsonSerialize());
    }

    public function testRoundTripWithResourceLinkAlongsideOtherContentTypes(): void
    {
        $original = new CallToolResult([
            new TextContent('25 results found'),
            new ResourceLink('file:///a.png', 'a.png', mimeType: 'image/png'),
            new ImageContent(base64_encode('binary'), 'image/png'),
            new AudioContent(base64_encode('binary'), 'audio/mpeg'),
            EmbeddedResource::fromText('file:///readme.txt', 'hello'),
        ]);

        $decoded = json_decode(json_encode($original, \JSON_THROW_ON_ERROR), true);
        $rehydrated = CallToolResult::fromArray($decoded);

        $this->assertCount(5, $rehydrated->content);
        $this->assertInstanceOf(TextContent::class, $rehydrated->content[0]);
        $this->assertInstanceOf(ResourceLink::class, $rehydrated->content[1]);
        $this->assertInstanceOf(ImageContent::class, $rehydrated->content[2]);
        $this->assertInstanceOf(AudioContent::class, $rehydrated->content[3]);
        $this->assertInstanceOf(EmbeddedResource::class, $rehydrated->content[4]);

        $this->assertSame('file:///a.png', $rehydrated->content[1]->uri);
        $this->assertSame('a.png', $rehydrated->content[1]->name);
        $this->assertSame('image/png', $rehydrated->content[1]->mimeType);
    }
}
