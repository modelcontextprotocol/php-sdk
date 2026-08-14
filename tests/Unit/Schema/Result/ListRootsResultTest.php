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
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use PHPUnit\Framework\TestCase;

final class ListRootsResultTest extends TestCase
{
    public function testConstructor(): void
    {
        $roots = [new Root('file:///home/user/project', 'project')];
        $result = new ListRootsResult($roots);

        $this->assertSame($roots, $result->roots);
        $this->assertNull($result->meta);
    }

    public function testFromArray(): void
    {
        $result = ListRootsResult::fromArray([
            'roots' => [
                ['uri' => 'file:///home/user/project', 'name' => 'project'],
                ['uri' => 'file:///tmp'],
            ],
        ]);

        $this->assertCount(2, $result->roots);
        $this->assertSame('file:///home/user/project', $result->roots[0]->uri);
        $this->assertSame('project', $result->roots[0]->name);
        $this->assertSame('file:///tmp', $result->roots[1]->uri);
        $this->assertNull($result->roots[1]->name);
        $this->assertNull($result->meta);
    }

    public function testFromArrayWithMeta(): void
    {
        $result = ListRootsResult::fromArray([
            'roots' => [['uri' => 'file:///tmp']],
            '_meta' => ['requestId' => 'abc'],
        ]);

        $this->assertSame(['requestId' => 'abc'], $result->meta);
    }

    public function testFromArrayWithMissingRoots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "roots"');

        /* @phpstan-ignore argument.type */
        ListRootsResult::fromArray([]);
    }

    public function testFromArrayWithNonArrayRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid root in ListRootsResult data, expected an array.');

        /* @phpstan-ignore argument.type */
        ListRootsResult::fromArray([
            'roots' => ['file:///tmp'],
        ]);
    }

    public function testFromArrayRejectsNonFileUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "file://"');

        ListRootsResult::fromArray([
            'roots' => [['uri' => 'https://example.com']],
        ]);
    }

    public function testFromArrayRoundTrip(): void
    {
        $data = [
            'roots' => [
                ['uri' => 'file:///home/user/project', 'name' => 'project'],
                ['uri' => 'file:///tmp'],
            ],
            '_meta' => ['foo' => 'bar'],
        ];

        $result = ListRootsResult::fromArray($data);

        $this->assertSame($data, json_decode(json_encode($result), true));
    }

    public function testJsonSerializeWithoutMeta(): void
    {
        $result = new ListRootsResult([new Root('file:///tmp')]);

        $this->assertSame([
            'roots' => [['uri' => 'file:///tmp']],
        ], json_decode(json_encode($result), true));
    }
}
