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
use Mcp\Schema\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResourceTest extends TestCase
{
    private const VALID_URI = 'https://example.com/list-books';

    public function testConstructorValid(): void
    {
        $uri = self::VALID_URI;

        $resource = new Resource(
            uri: $uri,
            name: 'list-books',
        );

        $this->assertInstanceOf(Resource::class, $resource);
        $this->assertSame($uri, $resource->uri);
    }

    public function testConstructorInvalid(): void
    {
        $uri = '/list-books';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid resource URI: "/list-books" must be a valid URI with a scheme and optional path.');

        $resource = new Resource(
            uri: $uri,
            name: 'list-books',
        );
    }

    public function testFromArrayValid(): void
    {
        $resource = Resource::fromArray([
            'uri' => self::VALID_URI,
            'name' => 'list-books',
        ]);

        $this->assertInstanceOf(Resource::class, $resource);
        $this->assertSame(self::VALID_URI, $resource->uri);
        $this->assertSame('list-books', $resource->name);
        $this->assertNull($resource->description);
        $this->assertNull($resource->meta);
    }

    #[DataProvider('provideInvalidResources')]
    public function testFromArrayInvalid(array $input, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        Resource::fromArray($input);
    }

    public static function provideInvalidResources(): iterable
    {
        yield 'missing uri' => [[], 'Invalid or missing "uri" in Resource data.'];
        yield 'missing name' => [
            ['uri' => self::VALID_URI],
            'Invalid or missing "name" in Resource data.',
        ];
        yield 'meta' => [
            [
                'uri' => self::VALID_URI,
                'name' => 'list-books',
                '_meta' => 'foo',
            ],
            'Invalid "_meta" in Resource data.',
        ];
    }
}
