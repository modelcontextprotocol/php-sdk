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
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\PrimitiveSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class PrimitiveSchemaDefinitionTest extends TestCase
{
    public function testFromArrayCreatesStringSchema(): void
    {
        $schema = PrimitiveSchemaDefinition::fromArray([
            'type' => 'string',
            'title' => 'Name',
        ]);

        $this->assertInstanceOf(StringSchemaDefinition::class, $schema);
        $this->assertSame('Name', $schema->title);
    }

    public function testFromArrayCreatesEnumSchemaForStringWithEnum(): void
    {
        $schema = PrimitiveSchemaDefinition::fromArray([
            'type' => 'string',
            'title' => 'Rating',
            'enum' => ['1', '2', '3'],
        ]);

        $this->assertInstanceOf(EnumSchemaDefinition::class, $schema);
        $this->assertSame('Rating', $schema->title);
        $this->assertSame(['1', '2', '3'], $schema->enum);
    }

    public function testFromArrayCreatesIntegerSchema(): void
    {
        $schema = PrimitiveSchemaDefinition::fromArray([
            'type' => 'integer',
            'title' => 'Age',
        ]);

        $this->assertInstanceOf(NumberSchemaDefinition::class, $schema);
        $this->assertSame('Age', $schema->title);
        $this->assertTrue($schema->integerOnly);
    }

    public function testFromArrayCreatesNumberSchema(): void
    {
        $schema = PrimitiveSchemaDefinition::fromArray([
            'type' => 'number',
            'title' => 'Price',
        ]);

        $this->assertInstanceOf(NumberSchemaDefinition::class, $schema);
        $this->assertSame('Price', $schema->title);
        $this->assertFalse($schema->integerOnly);
    }

    public function testFromArrayCreatesBooleanSchema(): void
    {
        $schema = PrimitiveSchemaDefinition::fromArray([
            'type' => 'boolean',
            'title' => 'Confirm',
        ]);

        $this->assertInstanceOf(BooleanSchemaDefinition::class, $schema);
        $this->assertSame('Confirm', $schema->title);
    }

    public function testFromArrayWithMissingType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "type"');

        /* @phpstan-ignore argument.type */
        PrimitiveSchemaDefinition::fromArray(['title' => 'Test']);
    }

    public function testFromArrayWithUnsupportedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported primitive type "object"');

        PrimitiveSchemaDefinition::fromArray([
            'type' => 'object',
            'title' => 'Test',
        ]);
    }

    public function testFromArrayWithArrayType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported primitive type "array"');

        PrimitiveSchemaDefinition::fromArray([
            'type' => 'array',
            'title' => 'Test',
        ]);
    }
}
