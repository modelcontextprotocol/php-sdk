<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Icon;
use Mcp\Schema\ResourceTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResourceTemplateTest extends TestCase
{
    private const VALID_URI = 'https://example.com/list-books/{id}';

    public function testConstructorValid(): void
    {
        $uri = self::VALID_URI;

        $resource = new ResourceTemplate(
            uriTemplate: $uri,
            name: 'list-books',
        );

        $this->assertInstanceOf(ResourceTemplate::class, $resource);
        $this->assertSame($uri, $resource->uriTemplate);
    }

    public function testConstructorInvalid(): void
    {
        $uri = '/list-books';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI template : "/list-books" must be a valid URI template with at least one placeholder.');

        $resource = new ResourceTemplate(
            uriTemplate: $uri,
            name: 'list-books',
        );
    }

    #[DataProvider('provideValidTemplates')]
    public function testConstructorAcceptsTemplates(string $uriTemplate): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: 'test-template',
        );

        $this->assertInstanceOf(ResourceTemplate::class, $resource);
        $this->assertSame($uriTemplate, $resource->uriTemplate);
    }

    public static function provideValidTemplates(): iterable
    {
        yield 'custom scheme without slashes' => ['config:{key}'];
        yield 'custom scheme with slashes' => ['config://{key}'];
        yield 'urn-style template' => ['urn:resource:{id}'];
    }

    public function testFromArrayValid(): void
    {
        $resource = ResourceTemplate::fromArray([
            'uriTemplate' => self::VALID_URI,
            'name' => 'list-books',
        ]);

        $this->assertInstanceOf(ResourceTemplate::class, $resource);
        $this->assertSame(self::VALID_URI, $resource->uriTemplate);
        $this->assertSame('list-books', $resource->name);
        $this->assertNull($resource->title);
        $this->assertNull($resource->description);
        $this->assertNull($resource->meta);
        $this->assertNull($resource->icons);
    }

    public function testIconsFromArray(): void
    {
        $resource = ResourceTemplate::fromArray([
            'uriTemplate' => self::VALID_URI,
            'name' => 'list-books',
            'icons' => [
                ['src' => 'https://example.com/books.png', 'mimeType' => 'image/png', 'sizes' => ['48x48']],
            ],
        ]);

        $this->assertIsArray($resource->icons);
        $this->assertCount(1, $resource->icons);
        $this->assertInstanceOf(Icon::class, $resource->icons[0]);
        $this->assertSame('https://example.com/books.png', $resource->icons[0]->src);
        $this->assertSame('image/png', $resource->icons[0]->mimeType);
        $this->assertSame(['48x48'], $resource->icons[0]->sizes);
    }

    public function testIconsSerialization(): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: self::VALID_URI,
            name: 'list-books',
            icons: [new Icon('https://example.com/books.png', 'image/png', ['48x48'])],
        );

        $this->assertSame(
            '{"uriTemplate":"https:\\/\\/example.com\\/list-books\\/{id}","name":"list-books","icons":[{"src":"https:\\/\\/example.com\\/books.png","mimeType":"image\\/png","sizes":["48x48"]}]}',
            json_encode($resource),
        );
    }

    public function testIconsOmittedWhenNull(): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: self::VALID_URI,
            name: 'list-books',
        );

        $this->assertArrayNotHasKey('icons', $resource->jsonSerialize());
    }

    public function testTitleFromArray(): void
    {
        $resource = ResourceTemplate::fromArray([
            'uriTemplate' => self::VALID_URI,
            'name' => 'list-books',
            'title' => 'Book Listing',
        ]);

        $this->assertSame('Book Listing', $resource->title);
    }

    public function testTitleSerialization(): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: self::VALID_URI,
            name: 'list-books',
            title: 'Book Listing',
        );

        $data = $resource->jsonSerialize();
        $this->assertSame('Book Listing', $data['title'] ?? null);
    }

    public function testTitleOmittedWhenNull(): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: self::VALID_URI,
            name: 'list-books',
        );

        $data = $resource->jsonSerialize();
        $this->assertArrayNotHasKey('title', $data);
    }

    /**
     * The specification puts no pattern on `name`; its own examples use
     * names such as "main.rs" and "Project Files".
     */
    #[DataProvider('provideSpecExampleNames')]
    public function testConstructorAcceptsAnyName(string $name): void
    {
        $resource = new ResourceTemplate(
            uriTemplate: self::VALID_URI,
            name: $name,
        );

        $this->assertSame($name, $resource->name);
    }

    public static function provideSpecExampleNames(): iterable
    {
        yield 'file name with dot' => ['main.rs'];
        yield 'display name with space' => ['Project Files'];
        yield 'uppercase file name' => ['README.md'];
        yield 'namespaced' => ['acme/reports:quarterly'];
        yield 'unicode' => ['Ünïcödé 名前'];
    }

    #[DataProvider('provideInvalidResources')]
    public function testFromArrayInvalid(array $input, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        ResourceTemplate::fromArray($input);
    }

    public static function provideInvalidResources(): iterable
    {
        yield 'missing uri' => [[], 'Invalid or missing "uriTemplate" in ResourceTemplate data.'];
        yield 'missing name' => [
            ['uriTemplate' => self::VALID_URI],
            'Invalid or missing "name" in ResourceTemplate data.',
        ];
        yield 'meta' => [
            [
                'uriTemplate' => self::VALID_URI,
                'name' => 'list-books',
                '_meta' => 'foo',
            ],
            'Invalid "_meta" in ResourceTemplate data.',
        ];
        yield 'icons entry that is not an object' => [
            [
                'uriTemplate' => self::VALID_URI,
                'name' => 'list-books',
                'icons' => ['https://example.com/books.png'],
            ],
            'Each entry in "icons" of ResourceTemplate data must be an array.',
        ];
    }
}
