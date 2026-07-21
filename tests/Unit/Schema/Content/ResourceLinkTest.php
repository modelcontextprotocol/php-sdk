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
use Mcp\Schema\Annotations;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Icon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceLinkTest extends TestCase
{
    private const VALID_URI = 'file:///project/src/main.rs';

    public function testConstructorSetsType(): void
    {
        $link = new ResourceLink(self::VALID_URI, 'main.rs');

        $this->assertSame('resource_link', $link->type);
        $this->assertSame(self::VALID_URI, $link->uri);
        $this->assertSame('main.rs', $link->name);
        $this->assertNull($link->title);
        $this->assertNull($link->description);
        $this->assertNull($link->mimeType);
        $this->assertNull($link->annotations);
        $this->assertNull($link->size);
        $this->assertNull($link->icons);
        $this->assertNull($link->meta);
    }

    public function testConstructorWithAllFields(): void
    {
        $annotations = new Annotations([Role::User], 0.5);
        $icons = [new Icon('https://example.com/icon.png')];

        $link = new ResourceLink(
            uri: self::VALID_URI,
            name: 'main.rs',
            title: 'Main Source File',
            description: 'Primary application entry point',
            mimeType: 'text/x-rust',
            annotations: $annotations,
            size: 1024,
            icons: $icons,
            meta: ['origin' => 'test'],
        );

        $this->assertSame(self::VALID_URI, $link->uri);
        $this->assertSame('main.rs', $link->name);
        $this->assertSame('Main Source File', $link->title);
        $this->assertSame('Primary application entry point', $link->description);
        $this->assertSame('text/x-rust', $link->mimeType);
        $this->assertSame($annotations, $link->annotations);
        $this->assertSame(1024, $link->size);
        $this->assertSame($icons, $link->icons);
        $this->assertSame(['origin' => 'test'], $link->meta);
    }

    public function testJsonSerializeMinimal(): void
    {
        $link = new ResourceLink(self::VALID_URI, 'main.rs');

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => self::VALID_URI,
            'name' => 'main.rs',
        ], $link->jsonSerialize());
    }

    public function testJsonSerializeWithAllFields(): void
    {
        $annotations = new Annotations([Role::User], 0.5);
        $icons = [new Icon('https://example.com/icon.png')];

        $link = new ResourceLink(
            uri: self::VALID_URI,
            name: 'main.rs',
            title: 'Main Source File',
            description: 'Primary application entry point',
            mimeType: 'text/x-rust',
            annotations: $annotations,
            size: 1024,
            icons: $icons,
            meta: ['origin' => 'test'],
        );

        $data = $link->jsonSerialize();

        $this->assertSame('resource_link', $data['type']);
        $this->assertSame(self::VALID_URI, $data['uri']);
        $this->assertSame('main.rs', $data['name']);
        $this->assertSame('Main Source File', $data['title']);
        $this->assertSame('Primary application entry point', $data['description']);
        $this->assertSame('text/x-rust', $data['mimeType']);
        $this->assertSame($annotations, $data['annotations']);
        $this->assertSame(1024, $data['size']);
        $this->assertSame($icons, $data['icons']);
        $this->assertSame(['origin' => 'test'], $data['_meta']);
    }

    public function testOptionalFieldsOmittedWhenNull(): void
    {
        $link = new ResourceLink(self::VALID_URI, 'main.rs');
        $data = $link->jsonSerialize();

        $this->assertArrayNotHasKey('title', $data);
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('mimeType', $data);
        $this->assertArrayNotHasKey('annotations', $data);
        $this->assertArrayNotHasKey('size', $data);
        $this->assertArrayNotHasKey('icons', $data);
        $this->assertArrayNotHasKey('_meta', $data);
    }

    public function testFromArrayMinimal(): void
    {
        $link = ResourceLink::fromArray([
            'type' => 'resource_link',
            'uri' => self::VALID_URI,
            'name' => 'main.rs',
        ]);

        $this->assertSame(self::VALID_URI, $link->uri);
        $this->assertSame('main.rs', $link->name);
        $this->assertNull($link->title);
        $this->assertNull($link->annotations);
        $this->assertNull($link->icons);
        $this->assertNull($link->meta);
    }

    public function testFromArrayWithAllFields(): void
    {
        $link = ResourceLink::fromArray([
            'type' => 'resource_link',
            'uri' => self::VALID_URI,
            'name' => 'main.rs',
            'title' => 'Main Source File',
            'description' => 'Primary application entry point',
            'mimeType' => 'text/x-rust',
            'annotations' => ['audience' => ['user'], 'priority' => 0.5],
            'size' => 1024,
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['origin' => 'test'],
        ]);

        $this->assertSame('Main Source File', $link->title);
        $this->assertSame('Primary application entry point', $link->description);
        $this->assertSame('text/x-rust', $link->mimeType);
        $this->assertInstanceOf(Annotations::class, $link->annotations);
        $this->assertSame(1024, $link->size);
        $this->assertCount(1, $link->icons);
        $this->assertInstanceOf(Icon::class, $link->icons[0]);
        $this->assertSame(['origin' => 'test'], $link->meta);
    }

    public function testRoundTripThroughJsonSerializeAndFromArray(): void
    {
        $original = new ResourceLink(
            uri: self::VALID_URI,
            name: 'main.rs',
            title: 'Main Source File',
            description: 'Primary application entry point',
            mimeType: 'text/x-rust',
            annotations: new Annotations([Role::User], 0.5),
            size: 1024,
            icons: [new Icon('https://example.com/icon.png')],
            meta: ['origin' => 'test'],
        );

        $decoded = json_decode(json_encode($original), true);
        $rehydrated = ResourceLink::fromArray($decoded);

        $this->assertSame($original->uri, $rehydrated->uri);
        $this->assertSame($original->name, $rehydrated->name);
        $this->assertSame($original->title, $rehydrated->title);
        $this->assertSame($original->description, $rehydrated->description);
        $this->assertSame($original->mimeType, $rehydrated->mimeType);
        $this->assertSame($original->size, $rehydrated->size);
        $this->assertSame($original->meta, $rehydrated->meta);
        $this->assertEquals($original->annotations, $rehydrated->annotations);
    }

    public function testFromArrayRejectsWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type for ResourceLink.');

        /* @phpstan-ignore argument.type */
        ResourceLink::fromArray([
            'type' => 'resource',
            'uri' => self::VALID_URI,
            'name' => 'main.rs',
        ]);
    }

    #[DataProvider('provideInvalidData')]
    public function testFromArrayRejectsInvalidData(array $input, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        ResourceLink::fromArray($input);
    }

    public static function provideInvalidData(): iterable
    {
        yield 'missing uri' => [
            ['type' => 'resource_link', 'name' => 'main.rs'],
            'Invalid or missing "uri" in ResourceLink data.',
        ];
        yield 'missing name' => [
            ['type' => 'resource_link', 'uri' => self::VALID_URI],
            'Invalid or missing "name" in ResourceLink data.',
        ];
        yield 'invalid _meta' => [
            ['type' => 'resource_link', 'uri' => self::VALID_URI, 'name' => 'main.rs', '_meta' => 'not-an-array'],
            'Invalid "_meta" in ResourceLink data.',
        ];
    }
}
