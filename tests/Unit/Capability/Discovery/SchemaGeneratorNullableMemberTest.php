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
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorNullableMemberTest extends TestCase
{
    public function testGenericWithNullElementAcceptsItsElements(): void
    {
        $schema = $this->schema('genericWithNullElement');

        $this->assertSame([], $this->validate(['values' => ['a', null]], $schema));
        $this->assertSame([], $this->validate(['values' => ['a']], $schema));
    }

    public function testGenericWithNullElementIsNotNullable(): void
    {
        $schema = $this->schema('genericWithNullElement');

        $this->assertSame('array', $schema['properties']['values']['type']);
        $this->assertNotSame([], $this->validate(['values' => null], $schema));
    }

    public function testShapeKeyWithNullableListAcceptsAList(): void
    {
        $schema = $this->schema('shapeWithNullableList');

        $this->assertSame([], $this->validate(['filter' => ['tags' => null]], $schema));
        $this->assertSame([], $this->validate(['filter' => ['tags' => ['a', 'b']]], $schema));
    }

    public function testShapeKeyWithNullableShapeKeepsTheInnerShape(): void
    {
        $schema = $this->schema('shapeWithNullableShape');

        $this->assertSame([], $this->validate(['input' => ['point' => null]], $schema));
        $this->assertSame([], $this->validate(['input' => ['point' => ['x' => 1]]], $schema));
        $this->assertNotSame([], $this->validate(['input' => ['point' => ['x' => 'not an int']]], $schema));
    }

    public function testClassNamedNullIsNotNullable(): void
    {
        $schema = $this->schema('classNamedNull');

        $this->assertNotContains('null', (array) $schema['properties']['logger']['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $method): array
    {
        return (new SchemaGenerator(new DocBlockParser()))
            ->generate(new \ReflectionMethod(NullableMemberFixture::class, $method));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<array<string, mixed>>
     */
    private function validate(mixed $data, array $schema): array
    {
        return (new SchemaValidator())->validateAgainstJsonSchema($data, $schema);
    }
}
