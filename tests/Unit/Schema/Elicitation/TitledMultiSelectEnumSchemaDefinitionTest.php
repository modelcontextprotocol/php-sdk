<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Elicitation;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Elicitation\TitledMultiSelectEnumSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class TitledMultiSelectEnumSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledMultiSelectEnumSchemaDefinition('Pick many', $anyOf);

        $this->assertSame('Pick many', $schema->title);
        $this->assertSame($anyOf, $schema->anyOf);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->minItems);
        $this->assertNull($schema->maxItems);
    }

    public function testConstructorWithAllParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
            ['const' => 'c', 'title' => 'Option C'],
        ];
        $schema = new TitledMultiSelectEnumSchemaDefinition(
            title: 'Pick many',
            anyOf: $anyOf,
            description: 'Select all that apply',
            default: ['a', 'c'],
            minItems: 1,
            maxItems: 3,
        );

        $this->assertSame('Pick many', $schema->title);
        $this->assertSame($anyOf, $schema->anyOf);
        $this->assertSame('Select all that apply', $schema->description);
        $this->assertSame(['a', 'c'], $schema->default);
        $this->assertSame(1, $schema->minItems);
        $this->assertSame(3, $schema->maxItems);
    }

    public function testConstructorWithEmptyAnyOf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('anyOf array must not be empty');

        new TitledMultiSelectEnumSchemaDefinition('Test', []);
    }

    public function testConstructorWithMissingConst(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each anyOf item must have a string "const" property');

        /* @phpstan-ignore argument.type */
        new TitledMultiSelectEnumSchemaDefinition('Test', [['title' => 'A']]);
    }

    public function testConstructorWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each anyOf item must have a string "title" property');

        /* @phpstan-ignore argument.type */
        new TitledMultiSelectEnumSchemaDefinition('Test', [['const' => 'a']]);
    }

    public function testConstructorWithNegativeMinItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minItems must be non-negative');

        new TitledMultiSelectEnumSchemaDefinition('Test', [['const' => 'a', 'title' => 'A']], minItems: -1);
    }

    public function testConstructorWithNegativeMaxItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxItems must be non-negative');

        new TitledMultiSelectEnumSchemaDefinition('Test', [['const' => 'a', 'title' => 'A']], maxItems: -1);
    }

    public function testConstructorWithMinItemsGreaterThanMaxItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minItems cannot be greater than maxItems');

        new TitledMultiSelectEnumSchemaDefinition(
            'Test',
            [['const' => 'a', 'title' => 'A'], ['const' => 'b', 'title' => 'B']],
            minItems: 3,
            maxItems: 1,
        );
    }

    public function testConstructorWithInvalidDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default value "invalid" is not in the anyOf const values');

        new TitledMultiSelectEnumSchemaDefinition(
            'Test',
            [['const' => 'a', 'title' => 'A']],
            default: ['invalid'],
        );
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = TitledMultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Pick many',
            'items' => ['anyOf' => $anyOf],
        ]);

        $this->assertSame('Pick many', $schema->title);
        $this->assertSame($anyOf, $schema->anyOf);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->minItems);
        $this->assertNull($schema->maxItems);
    }

    public function testFromArrayWithAllParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = TitledMultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Pick many',
            'description' => 'Select all that apply',
            'default' => ['a'],
            'minItems' => 1,
            'maxItems' => 2,
            'items' => ['anyOf' => $anyOf],
        ]);

        $this->assertSame('Pick many', $schema->title);
        $this->assertSame($anyOf, $schema->anyOf);
        $this->assertSame('Select all that apply', $schema->description);
        $this->assertSame(['a'], $schema->default);
        $this->assertSame(1, $schema->minItems);
        $this->assertSame(2, $schema->maxItems);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        TitledMultiSelectEnumSchemaDefinition::fromArray([
            'items' => ['anyOf' => [['const' => 'a', 'title' => 'A']]],
        ]);
    }

    public function testFromArrayWithMissingItemsAnyOf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "items.anyOf"');

        /* @phpstan-ignore argument.type */
        TitledMultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Test',
            'items' => [],
        ]);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledMultiSelectEnumSchemaDefinition('Pick many', $anyOf);

        $this->assertSame([
            'type' => 'array',
            'title' => 'Pick many',
            'items' => ['anyOf' => $anyOf],
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledMultiSelectEnumSchemaDefinition(
            title: 'Pick many',
            anyOf: $anyOf,
            description: 'Select all that apply',
            default: ['a'],
            minItems: 1,
            maxItems: 2,
        );

        $this->assertSame([
            'type' => 'array',
            'title' => 'Pick many',
            'description' => 'Select all that apply',
            'items' => ['anyOf' => $anyOf],
            'default' => ['a'],
            'minItems' => 1,
            'maxItems' => 2,
        ], $schema->jsonSerialize());
    }

    public function testFromArrayJsonSerializeRoundTrip(): void
    {
        $anyOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $original = new TitledMultiSelectEnumSchemaDefinition(
            title: 'Pick many',
            anyOf: $anyOf,
            description: 'Select all that apply',
            default: ['a', 'b'],
            minItems: 1,
            maxItems: 2,
        );

        $serialized = $original->jsonSerialize();
        $restored = TitledMultiSelectEnumSchemaDefinition::fromArray($serialized);

        $this->assertSame($serialized, $restored->jsonSerialize());
    }
}
