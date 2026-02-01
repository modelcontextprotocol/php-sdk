<?php

declare(strict_types=1);

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
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class EnumSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $schema = new EnumSchemaDefinition('Rating', ['1', '2', '3', '4', '5']);

        $this->assertSame('Rating', $schema->title);
        $this->assertSame(['1', '2', '3', '4', '5'], $schema->enum);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->enumNames);
    }

    public function testConstructorWithAllParams(): void
    {
        $schema = new EnumSchemaDefinition(
            title: 'Satisfaction',
            enum: ['poor', 'fair', 'good', 'excellent'],
            description: 'Rate your satisfaction',
            default: 'good',
            enumNames: ['Poor', 'Fair', 'Good', 'Excellent'],
        );

        $this->assertSame('Satisfaction', $schema->title);
        $this->assertSame(['poor', 'fair', 'good', 'excellent'], $schema->enum);
        $this->assertSame('Rate your satisfaction', $schema->description);
        $this->assertSame('good', $schema->default);
        $this->assertSame(['Poor', 'Fair', 'Good', 'Excellent'], $schema->enumNames);
    }

    public function testConstructorWithEmptyEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enum array must not be empty');

        new EnumSchemaDefinition('Test', []);
    }

    public function testConstructorWithNonStringEnumValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All enum values must be strings');

        /* @phpstan-ignore argument.type */
        new EnumSchemaDefinition('Test', ['a', 1, 'b']);
    }

    public function testConstructorWithEnumNamesMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enumNames length must match enum length');

        new EnumSchemaDefinition(
            title: 'Test',
            enum: ['a', 'b', 'c'],
            enumNames: ['A', 'B'],
        );
    }

    public function testConstructorWithInvalidDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default value "invalid" is not in the enum array');

        new EnumSchemaDefinition(
            title: 'Test',
            enum: ['a', 'b', 'c'],
            default: 'invalid',
        );
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $schema = EnumSchemaDefinition::fromArray([
            'title' => 'Rating',
            'enum' => ['1', '2', '3'],
        ]);

        $this->assertSame('Rating', $schema->title);
        $this->assertSame(['1', '2', '3'], $schema->enum);
    }

    public function testFromArrayWithAllParams(): void
    {
        $schema = EnumSchemaDefinition::fromArray([
            'title' => 'Satisfaction',
            'enum' => ['poor', 'fair', 'good'],
            'description' => 'Rate your satisfaction',
            'default' => 'fair',
            'enumNames' => ['Poor', 'Fair', 'Good'],
        ]);

        $this->assertSame('Satisfaction', $schema->title);
        $this->assertSame(['poor', 'fair', 'good'], $schema->enum);
        $this->assertSame('Rate your satisfaction', $schema->description);
        $this->assertSame('fair', $schema->default);
        $this->assertSame(['Poor', 'Fair', 'Good'], $schema->enumNames);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        EnumSchemaDefinition::fromArray(['enum' => ['a', 'b']]);
    }

    public function testFromArrayWithMissingEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "enum"');

        /* @phpstan-ignore argument.type */
        EnumSchemaDefinition::fromArray(['title' => 'Test']);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $schema = new EnumSchemaDefinition('Rating', ['1', '2', '3']);

        $this->assertSame([
            'type' => 'string',
            'title' => 'Rating',
            'enum' => ['1', '2', '3'],
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $schema = new EnumSchemaDefinition(
            title: 'Satisfaction',
            enum: ['poor', 'fair', 'good'],
            description: 'Rate your satisfaction',
            default: 'fair',
            enumNames: ['Poor', 'Fair', 'Good'],
        );

        $this->assertSame([
            'type' => 'string',
            'title' => 'Satisfaction',
            'enum' => ['poor', 'fair', 'good'],
            'description' => 'Rate your satisfaction',
            'default' => 'fair',
            'enumNames' => ['Poor', 'Fair', 'Good'],
        ], $schema->jsonSerialize());
    }
}
