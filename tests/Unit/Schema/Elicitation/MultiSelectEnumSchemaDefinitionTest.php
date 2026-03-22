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
use Mcp\Schema\Elicitation\MultiSelectEnumSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class MultiSelectEnumSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $schema = new MultiSelectEnumSchemaDefinition('Tags', ['php', 'js', 'go']);

        $this->assertSame('Tags', $schema->title);
        $this->assertSame(['php', 'js', 'go'], $schema->enum);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->minItems);
        $this->assertNull($schema->maxItems);
    }

    public function testConstructorWithAllParams(): void
    {
        $schema = new MultiSelectEnumSchemaDefinition(
            title: 'Tags',
            enum: ['php', 'js', 'go'],
            description: 'Select languages',
            default: ['php'],
            minItems: 1,
            maxItems: 3,
        );

        $this->assertSame('Tags', $schema->title);
        $this->assertSame(['php', 'js', 'go'], $schema->enum);
        $this->assertSame('Select languages', $schema->description);
        $this->assertSame(['php'], $schema->default);
        $this->assertSame(1, $schema->minItems);
        $this->assertSame(3, $schema->maxItems);
    }

    public function testConstructorWithEmptyEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enum array must not be empty');

        new MultiSelectEnumSchemaDefinition('Test', []);
    }

    public function testConstructorWithNonStringEnumValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All enum values must be strings');

        /* @phpstan-ignore argument.type */
        new MultiSelectEnumSchemaDefinition('Test', ['a', 1, 'b']);
    }

    public function testConstructorWithNegativeMinItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minItems must be non-negative');

        new MultiSelectEnumSchemaDefinition('Test', ['a'], minItems: -1);
    }

    public function testConstructorWithNegativeMaxItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxItems must be non-negative');

        new MultiSelectEnumSchemaDefinition('Test', ['a'], maxItems: -1);
    }

    public function testConstructorWithMinItemsGreaterThanMaxItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minItems cannot be greater than maxItems');

        new MultiSelectEnumSchemaDefinition('Test', ['a', 'b'], minItems: 3, maxItems: 1);
    }

    public function testConstructorWithInvalidDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default value "invalid" is not in the enum array');

        new MultiSelectEnumSchemaDefinition('Test', ['a', 'b'], default: ['invalid']);
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $schema = MultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Tags',
            'items' => [
                'type' => 'string',
                'enum' => ['php', 'js', 'go'],
            ],
        ]);

        $this->assertSame('Tags', $schema->title);
        $this->assertSame(['php', 'js', 'go'], $schema->enum);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->minItems);
        $this->assertNull($schema->maxItems);
    }

    public function testFromArrayWithAllParams(): void
    {
        $schema = MultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Tags',
            'description' => 'Select languages',
            'default' => ['php'],
            'minItems' => 1,
            'maxItems' => 3,
            'items' => [
                'type' => 'string',
                'enum' => ['php', 'js', 'go'],
            ],
        ]);

        $this->assertSame('Tags', $schema->title);
        $this->assertSame(['php', 'js', 'go'], $schema->enum);
        $this->assertSame('Select languages', $schema->description);
        $this->assertSame(['php'], $schema->default);
        $this->assertSame(1, $schema->minItems);
        $this->assertSame(3, $schema->maxItems);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        MultiSelectEnumSchemaDefinition::fromArray([
            'items' => ['type' => 'string', 'enum' => ['a']],
        ]);
    }

    public function testFromArrayWithMissingItemsEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "items.enum"');

        /* @phpstan-ignore argument.type */
        MultiSelectEnumSchemaDefinition::fromArray([
            'title' => 'Test',
            'items' => ['type' => 'string'],
        ]);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $schema = new MultiSelectEnumSchemaDefinition('Tags', ['php', 'js', 'go']);

        $this->assertSame([
            'type' => 'array',
            'title' => 'Tags',
            'items' => [
                'type' => 'string',
                'enum' => ['php', 'js', 'go'],
            ],
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $schema = new MultiSelectEnumSchemaDefinition(
            title: 'Tags',
            enum: ['php', 'js', 'go'],
            description: 'Select languages',
            default: ['php'],
            minItems: 1,
            maxItems: 3,
        );

        $this->assertSame([
            'type' => 'array',
            'title' => 'Tags',
            'description' => 'Select languages',
            'items' => [
                'type' => 'string',
                'enum' => ['php', 'js', 'go'],
            ],
            'default' => ['php'],
            'minItems' => 1,
            'maxItems' => 3,
        ], $schema->jsonSerialize());
    }

    public function testFromArrayJsonSerializeRoundTrip(): void
    {
        $original = new MultiSelectEnumSchemaDefinition(
            title: 'Tags',
            enum: ['php', 'js', 'go'],
            description: 'Select languages',
            default: ['php', 'go'],
            minItems: 1,
            maxItems: 3,
        );

        $serialized = $original->jsonSerialize();
        $restored = MultiSelectEnumSchemaDefinition::fromArray($serialized);

        $this->assertSame($serialized, $restored->jsonSerialize());
    }
}
