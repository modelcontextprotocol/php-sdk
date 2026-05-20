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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ResourceDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResourceDefinitionTest extends TestCase
{
    private const VALID_URI = 'https://example.com/list-books';

    public function testConstructorValid(): void
    {
        $uri = self::VALID_URI;

        $resource = new ResourceDefinition(
            uri: $uri,
            name: 'list-books',
        );

        $this->assertInstanceOf(ResourceDefinition::class, $resource);
        $this->assertSame($uri, $resource->uri);
    }

    public function testConstructorInvalid(): void
    {
        $uri = '/list-books';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid resource URI: "/list-books" must be a valid URI with a scheme and optional path.');

        $resource = new ResourceDefinition(
            uri: $uri,
            name: 'list-books',
        );
    }

    #[DataProvider('provideValidUris')]
    public function testConstructorAcceptsUris(string $uri): void
    {
        $resource = new ResourceDefinition(
            uri: $uri,
            name: 'test-resource',
        );

        $this->assertInstanceOf(ResourceDefinition::class, $resource);
        $this->assertSame($uri, $resource->uri);
    }

    public static function provideValidUris(): iterable
    {
        yield 'urn' => ['urn:isbn:0451450523'];
        yield 'mailto' => ['mailto:user@example.com'];
        yield 'data' => ['data:text/plain;base64,SGVsbG8='];
        yield 'custom scheme without slashes' => ['config:myapp/settings'];
        yield 'custom scheme with slashes' => ['config://myapp/settings'];
    }

    public function testFromArrayValid(): void
    {
        $resource = ResourceDefinition::fromArray([
            'uri' => self::VALID_URI,
            'name' => 'list-books',
        ]);

        $this->assertInstanceOf(ResourceDefinition::class, $resource);
        $this->assertSame(self::VALID_URI, $resource->uri);
        $this->assertSame('list-books', $resource->name);
        $this->assertNull($resource->title);
        $this->assertNull($resource->description);
        $this->assertNull($resource->meta);
    }

    public function testTitleFromArray(): void
    {
        $resource = ResourceDefinition::fromArray([
            'uri' => self::VALID_URI,
            'name' => 'list-books',
            'title' => 'Book Listing',
        ]);

        $this->assertSame('Book Listing', $resource->title);
    }

    public function testTitleSerialization(): void
    {
        $resource = new ResourceDefinition(
            uri: self::VALID_URI,
            name: 'list-books',
            title: 'Book Listing',
        );

        $data = $resource->jsonSerialize();
        $this->assertSame('Book Listing', $data['title']);
    }

    public function testTitleOmittedWhenNull(): void
    {
        $resource = new ResourceDefinition(
            uri: self::VALID_URI,
            name: 'list-books',
        );

        $data = $resource->jsonSerialize();
        $this->assertArrayNotHasKey('title', $data);
    }

    #[DataProvider('provideInvalidResources')]
    public function testFromArrayInvalid(array $input, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        ResourceDefinition::fromArray($input);
    }

    public static function provideInvalidResources(): iterable
    {
        yield 'missing uri' => [[], 'Invalid or missing "uri" in ResourceDefinition data.'];
        yield 'missing name' => [
            ['uri' => self::VALID_URI],
            'Invalid or missing "name" in ResourceDefinition data.',
        ];
        yield 'meta' => [
            [
                'uri' => self::VALID_URI,
                'name' => 'list-books',
                '_meta' => 'foo',
            ],
            'Invalid "_meta" in ResourceDefinition data.',
        ];
    }
}
