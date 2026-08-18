<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class SchemaGeneratorUnionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function unions(): iterable
    {
        yield 'not a union' => ['string', ['string']];
        yield 'simple union' => ['int|string', ['int', 'string']];
        yield 'three branches' => ['int|string|bool', ['int', 'string', 'bool']];
        yield 'array branch alongside a scalar' => ['string[]|int', ['string[]', 'int']];
        yield 'generic branch alongside a scalar' => ['array<string>|int', ['array<string>', 'int']];

        // The `|` here parameterises the outer type; splitting it would turn
        // one type into two that do not exist.
        yield 'union inside a generic' => ['array<int|string>', ['array<int|string>']];
        yield 'union inside a shape' => ['array{a: int|string}', ['array{a: int|string}']];
        yield 'nested generics' => ['array<string, array<int|bool>>', ['array<string, array<int|bool>>']];
        yield 'shape beside a scalar' => ['array{a: int|string}|null', ['array{a: int|string}', 'null']];

        yield 'whitespace is trimmed' => ['int | string', ['int', 'string']];
        yield 'empty branches are dropped' => ['int||string', ['int', 'string']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('unions')]
    #[TestDox('a type string splits on its top-level union only')]
    public function testSplitUnion(string $type, array $expected): void
    {
        $this->assertSame($expected, SchemaGenerator::splitUnion($type));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mappedTypes(): iterable
    {
        yield 'scalar union' => ['int|string', ['integer', 'string']];

        // The regression: the `[]` check ran before the union split, so this
        // came back as ['array'] and the `int` branch vanished.
        yield 'array beside a scalar keeps both' => ['string[]|int', ['array', 'integer']];
        yield 'scalar before an array keeps both' => ['int|string[]', ['integer', 'array']];
        yield 'generic beside a scalar keeps both' => ['array<string>|bool', ['array', 'boolean']];

        yield 'a generic union is still one array' => ['array<int|string>', ['array']];
        yield 'a shape is still one object' => ['array{a: int|string}', ['object']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('mappedTypes')]
    #[TestDox('a union maps to every JSON Schema type its branches need')]
    public function testMappedTypes(string $type, array $expected): void
    {
        $generator = new SchemaGenerator(new DocBlockParser());

        $map = new \ReflectionMethod($generator, 'mapPhpTypeToJsonSchemaType');

        $this->assertSame($expected, $map->invoke($generator, $type));
    }

    #[TestDox('a generated schema keeps both branches of an array-or-scalar union')]
    public function testGeneratedUnionSchemaKeepsBothBranches(): void
    {
        $schema = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new \ReflectionMethod(UnionFixture::class, 'handle'));

        // `items` constrains the instance only when it *is* an array, so it
        // coexists with the scalar branch instead of excluding it.
        $this->assertSame(['array', 'integer'], $schema['properties']['mixedish']['type']);
        // The array branch keeps its own element-type constraint rather than
        // losing it to the union as a whole (`items` would otherwise be `{}`).
        $this->assertSame(['type' => 'string'], $schema['properties']['mixedish']['items']);

        $this->assertSame(['integer', 'string'], $schema['properties']['scalars']['type']);
        $this->assertSame('array', $schema['properties']['arrayOnly']['type']);
        $this->assertSame(['array', 'null'], $schema['properties']['nullableArray']['type']);
    }

    #[TestDox('a value from either branch validates against the generated schema')]
    public function testBothBranchesValidate(): void
    {
        $schema = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new \ReflectionMethod(UnionFixture::class, 'handle'));

        $validator = new SchemaValidator();
        $base = ['scalars' => 1, 'arrayOnly' => ['a'], 'nullableArray' => null];

        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => ['a', 'b']], $schema));
        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 42], $schema));

        // A float is in neither branch, so it is still refused.
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 1.5], $schema));
    }
}
