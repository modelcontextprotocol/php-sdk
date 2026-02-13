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

    public function testFromArrayValid(): void
    {
        $resource = ResourceTemplate::fromArray([
            'uriTemplate' => self::VALID_URI,
            'name' => 'list-books',
        ]);

        $this->assertInstanceOf(ResourceTemplate::class, $resource);
        $this->assertSame(self::VALID_URI, $resource->uriTemplate);
        $this->assertSame('list-books', $resource->name);
        $this->assertNull($resource->description);
        $this->assertNull($resource->meta);
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
    }
}
