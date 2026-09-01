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
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ToolHeaderAnnotationTest extends TestCase
{
    /**
     * @param array<string, mixed> $properties
     */
    private static function tool(array $properties): Tool
    {
        return new Tool(
            name: 'a_tool',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => $properties, 'required' => null],
            description: 'x',
            annotations: null,
        );
    }

    #[TestDox('a well-formed annotation is accepted')]
    public function testValidAnnotationIsAccepted(): void
    {
        $tool = self::tool([
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'retries' => ['type' => 'integer', 'x-mcp-header' => 'Retries'],
            'dry_run' => ['type' => 'boolean', 'x-mcp-header' => 'Dry-Run'],
            'query' => ['type' => 'string'],
        ]);

        $this->assertSame('a_tool', $tool->name);
    }

    #[TestDox('an annotation on a nested property is accepted: the chain is all properties')]
    public function testNestedAnnotationIsAccepted(): void
    {
        $tool = self::tool([
            'target' => [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
            ],
        ]);

        $this->assertSame('a_tool', $tool->name);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidAnnotations(): iterable
    {
        yield 'empty name' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => '']],
            'is empty',
        ];

        yield 'name with a space' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => 'My Header']],
            'not a valid HTTP field name',
        ];

        yield 'name with a newline' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => "X\nInjected: yes"]],
            'not a valid HTTP field name',
        ];

        yield 'name with a carriage return' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => "X\rY"]],
            'not a valid HTTP field name',
        ];

        yield 'name with a colon' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => 'X:Y']],
            'not a valid HTTP field name',
        ];

        yield 'duplicate, differing only in case' => [
            [
                'a' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'b' => ['type' => 'string', 'x-mcp-header' => 'region'],
            ],
            'declared twice',
        ];

        yield 'on a number' => [
            ['a' => ['type' => 'number', 'x-mcp-header' => 'Amount']],
            'cannot be mirrored',
        ];

        yield 'on an array' => [
            ['a' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-mcp-header' => 'Tags']],
            'only string, integer and boolean',
        ];

        yield 'on an object' => [
            ['a' => ['type' => 'object', 'x-mcp-header' => 'Blob']],
            'only string, integer and boolean',
        ];

        yield 'non-string annotation value' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => 42]],
            'is empty',
        ];

        yield 'duplicate across nesting levels' => [
            [
                'a' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'nested' => [
                    'type' => 'object',
                    'properties' => ['b' => ['type' => 'string', 'x-mcp-header' => 'Region']],
                ],
            ],
            'declared twice',
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    #[DataProvider('invalidAnnotations')]
    #[TestDox('an out-of-bounds annotation makes the tool definition invalid')]
    public function testInvalidAnnotationIsRefused(array $properties, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');

        self::tool($properties);
    }

    #[TestDox('an annotation the walk cannot reach statically is simply not seen')]
    public function testUnreachableAnnotationIsIgnored(): void
    {
        // Under `items`, so it is not reachable through `properties` alone.
        // The spec calls such a definition invalid; this SDK does not mirror
        // what it cannot reach, and does not pretend the annotation exists.
        $tool = self::tool([
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'x-mcp-header' => 'Bad Name With Spaces'],
            ],
        ]);

        $this->assertSame('a_tool', $tool->name);
    }
}
