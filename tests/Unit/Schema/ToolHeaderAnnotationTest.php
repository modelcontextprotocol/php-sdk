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

        yield 'null annotation value' => [
            ['a' => ['type' => 'string', 'x-mcp-header' => null]],
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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreachableAnnotations(): iterable
    {
        $annotated = ['type' => 'string', 'x-mcp-header' => 'Region'];

        yield 'under items' => [['properties' => ['tags' => ['type' => 'array', 'items' => $annotated]]]];
        yield 'under anyOf' => [['anyOf' => [['properties' => ['region' => $annotated]]]]];
        yield 'under oneOf of a property' => [['properties' => ['region' => ['oneOf' => [$annotated]]]]];
        yield 'under not' => [['not' => ['properties' => ['region' => $annotated]]]];
        yield 'under if' => [['if' => ['properties' => ['region' => $annotated]]]];
        yield 'under $defs, through $ref' => [[
            '$defs' => ['target' => ['type' => 'object', 'properties' => ['region' => $annotated]]],
            'properties' => ['target' => ['$ref' => '#/$defs/target']],
        ]];
        yield 'under additionalProperties' => [['additionalProperties' => $annotated]];
        yield 'on the root' => [['x-mcp-header' => 'Region']];
        yield 'non-string value under items' => [['properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => ['Region']]]]]];
        yield 'null value under items' => [['properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => null]]]]];
        yield 'under a definition named default' => [['$defs' => ['default' => $annotated]]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unreachableAnnotations')]
    #[TestDox('an annotation not reachable through properties alone makes the tool definition invalid')]
    public function testUnreachableAnnotationIsRefused(array $schema): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not reachable through "properties" alone');

        new Tool(name: 'a_tool', title: null, inputSchema: ['type' => 'object', ...$schema], description: 'x', annotations: null);
    }

    #[TestDox('a property named x-mcp-header or one in instance data is no annotation')]
    public function testLookalikeIsNotAnAnnotation(): void
    {
        $tool = self::tool([
            'x-mcp-header' => ['type' => 'string'],
            'target' => ['type' => 'object', 'default' => ['x-mcp-header' => 'Region']],
            'nested' => ['type' => 'object', 'items' => ['properties' => ['x-mcp-header' => true]]],
        ]);

        $this->assertSame('a_tool', $tool->name);
    }

    #[TestDox('a definition named x-mcp-header is no annotation')]
    public function testDefinitionNamedLikeTheAnnotationIsNotAnAnnotation(): void
    {
        $tool = new Tool(name: 'a_tool', title: null, inputSchema: ['type' => 'object', '$defs' => ['x-mcp-header' => true]], description: 'x', annotations: null);

        $this->assertSame('a_tool', $tool->name);
    }

    #[TestDox('a key named x-mcp-header under a keyword that holds no subschema is no annotation')]
    public function testNonSchemaKeywordIsNotWalked(): void
    {
        $tool = new Tool(name: 'a_tool', title: null, inputSchema: [
            'type' => 'object',
            'properties' => ['region' => ['type' => 'string']],
            'dependentRequired' => ['x-mcp-header' => ['region']],
            'x-ui' => ['x-mcp-header' => 'Region'],
        ], description: 'x', annotations: null);

        $this->assertSame('a_tool', $tool->name);
    }
}
