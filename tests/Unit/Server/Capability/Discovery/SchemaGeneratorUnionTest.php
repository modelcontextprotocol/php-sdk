<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Discovery;

use Mcp\Server\Capability\Discovery\SchemaGenerator;
use Mcp\Server\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class SchemaGeneratorUnionTest extends TestCase
{
    #[TestDox('a generated schema keeps both branches of an array-or-scalar union')]
    public function testGeneratedUnionSchemaKeepsBothBranches(): void
    {
        $schema = (new SchemaGenerator())
            ->generate(new \ReflectionMethod(UnionFixture::class, 'handle'));

        $this->assertSame([['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'integer']], $schema['properties']['mixedish']['anyOf']);
        $this->assertSame([['type' => 'integer'], ['type' => 'string']], $schema['properties']['scalars']['anyOf']);
        $this->assertSame(['type' => 'string'], $schema['properties']['arrayOnly']['items']);
        $this->assertSame(['array', 'null'], $schema['properties']['nullableArray']['type']);

        // Each array branch keeps its own element type.
        $this->assertSame([
            ['type' => 'array', 'items' => ['type' => 'integer']],
            ['type' => 'array', 'items' => ['type' => 'string']],
        ], $schema['properties']['disagreeingArrays']['anyOf']);
    }

    #[TestDox('a value from either branch validates against the generated schema')]
    public function testBothBranchesValidate(): void
    {
        $schema = (new SchemaGenerator())
            ->generate(new \ReflectionMethod(UnionFixture::class, 'handle'));

        $validator = new SchemaValidator();
        $base = ['scalars' => 1, 'arrayOnly' => ['a'], 'nullableArray' => null, 'disagreeingArrays' => []];

        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => ['a', 'b']], $schema));
        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 42], $schema));

        // A float is in neither branch, so it is still refused.
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 1.5], $schema));

        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 42, 'disagreeingArrays' => ['a', 'b']], $schema));
        $this->assertSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 42, 'disagreeingArrays' => [1, 2]], $schema));
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([...$base, 'mixedish' => 42, 'disagreeingArrays' => [1, 'a']], $schema));
    }
}
