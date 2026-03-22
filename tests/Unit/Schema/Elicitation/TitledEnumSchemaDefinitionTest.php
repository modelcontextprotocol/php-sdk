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
use Mcp\Schema\Elicitation\TitledEnumSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class TitledEnumSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledEnumSchemaDefinition('Pick one', $oneOf);

        $this->assertSame('Pick one', $schema->title);
        $this->assertSame($oneOf, $schema->oneOf);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
    }

    public function testConstructorWithAllParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledEnumSchemaDefinition(
            title: 'Pick one',
            oneOf: $oneOf,
            description: 'Choose wisely',
            default: 'b',
        );

        $this->assertSame('Pick one', $schema->title);
        $this->assertSame($oneOf, $schema->oneOf);
        $this->assertSame('Choose wisely', $schema->description);
        $this->assertSame('b', $schema->default);
    }

    public function testConstructorWithEmptyOneOf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oneOf array must not be empty');

        new TitledEnumSchemaDefinition('Test', []);
    }

    public function testConstructorWithMissingConst(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each oneOf item must have a string "const" property');

        /* @phpstan-ignore argument.type */
        new TitledEnumSchemaDefinition('Test', [['title' => 'A']]);
    }

    public function testConstructorWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each oneOf item must have a string "title" property');

        /* @phpstan-ignore argument.type */
        new TitledEnumSchemaDefinition('Test', [['const' => 'a']]);
    }

    public function testConstructorWithInvalidDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default value "invalid" is not in the oneOf const values');

        new TitledEnumSchemaDefinition(
            title: 'Test',
            oneOf: [['const' => 'a', 'title' => 'A']],
            default: 'invalid',
        );
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = TitledEnumSchemaDefinition::fromArray([
            'title' => 'Pick one',
            'oneOf' => $oneOf,
        ]);

        $this->assertSame('Pick one', $schema->title);
        $this->assertSame($oneOf, $schema->oneOf);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
    }

    public function testFromArrayWithAllParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = TitledEnumSchemaDefinition::fromArray([
            'title' => 'Pick one',
            'oneOf' => $oneOf,
            'description' => 'Choose wisely',
            'default' => 'b',
        ]);

        $this->assertSame('Pick one', $schema->title);
        $this->assertSame($oneOf, $schema->oneOf);
        $this->assertSame('Choose wisely', $schema->description);
        $this->assertSame('b', $schema->default);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        TitledEnumSchemaDefinition::fromArray(['oneOf' => [['const' => 'a', 'title' => 'A']]]);
    }

    public function testFromArrayWithMissingOneOf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "oneOf"');

        /* @phpstan-ignore argument.type */
        TitledEnumSchemaDefinition::fromArray(['title' => 'Test']);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledEnumSchemaDefinition('Pick one', $oneOf);

        $this->assertSame([
            'type' => 'string',
            'title' => 'Pick one',
            'oneOf' => $oneOf,
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $schema = new TitledEnumSchemaDefinition(
            title: 'Pick one',
            oneOf: $oneOf,
            description: 'Choose wisely',
            default: 'b',
        );

        $this->assertSame([
            'type' => 'string',
            'title' => 'Pick one',
            'description' => 'Choose wisely',
            'oneOf' => $oneOf,
            'default' => 'b',
        ], $schema->jsonSerialize());
    }

    public function testFromArrayJsonSerializeRoundTrip(): void
    {
        $oneOf = [
            ['const' => 'a', 'title' => 'Option A'],
            ['const' => 'b', 'title' => 'Option B'],
        ];
        $original = new TitledEnumSchemaDefinition(
            title: 'Pick one',
            oneOf: $oneOf,
            description: 'Choose wisely',
            default: 'b',
        );

        $serialized = $original->jsonSerialize();
        $restored = TitledEnumSchemaDefinition::fromArray($serialized);

        $this->assertSame($serialized, $restored->jsonSerialize());
    }
}
