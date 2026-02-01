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
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class NumberSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $schema = new NumberSchemaDefinition('Age');

        $this->assertSame('Age', $schema->title);
        $this->assertFalse($schema->integerOnly);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->minimum);
        $this->assertNull($schema->maximum);
    }

    public function testConstructorWithAllParams(): void
    {
        $schema = new NumberSchemaDefinition(
            title: 'Party Size',
            integerOnly: true,
            description: 'Number of guests',
            default: 2,
            minimum: 1,
            maximum: 10,
        );

        $this->assertSame('Party Size', $schema->title);
        $this->assertTrue($schema->integerOnly);
        $this->assertSame('Number of guests', $schema->description);
        $this->assertSame(2, $schema->default);
        $this->assertSame(1, $schema->minimum);
        $this->assertSame(10, $schema->maximum);
    }

    public function testConstructorWithFloatValues(): void
    {
        $schema = new NumberSchemaDefinition(
            title: 'Temperature',
            default: 36.5,
            minimum: 35.0,
            maximum: 42.0,
        );

        $this->assertSame(36.5, $schema->default);
        $this->assertSame(35.0, $schema->minimum);
        $this->assertSame(42.0, $schema->maximum);
    }

    public function testConstructorWithMinimumGreaterThanMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum cannot be greater than maximum');

        new NumberSchemaDefinition('Test', minimum: 10, maximum: 5);
    }

    public function testConstructorWithDefaultBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default value cannot be less than minimum');

        new NumberSchemaDefinition('Test', default: 5, minimum: 10);
    }

    public function testConstructorWithDefaultAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default value cannot be greater than maximum');

        new NumberSchemaDefinition('Test', default: 15, maximum: 10);
    }

    public function testConstructorWithNonIntegerDefaultWhenIntegerOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default value must be an integer when integerOnly is true');

        new NumberSchemaDefinition('Test', integerOnly: true, default: 5.5);
    }

    public function testFromArrayWithIntegerType(): void
    {
        $schema = NumberSchemaDefinition::fromArray([
            'type' => 'integer',
            'title' => 'Count',
        ]);

        $this->assertTrue($schema->integerOnly);
    }

    public function testFromArrayWithNumberType(): void
    {
        $schema = NumberSchemaDefinition::fromArray([
            'type' => 'number',
            'title' => 'Price',
        ]);

        $this->assertFalse($schema->integerOnly);
    }

    public function testFromArrayWithAllParams(): void
    {
        $schema = NumberSchemaDefinition::fromArray([
            'type' => 'integer',
            'title' => 'Party Size',
            'description' => 'Number of guests',
            'default' => 2,
            'minimum' => 1,
            'maximum' => 10,
        ]);

        $this->assertSame('Party Size', $schema->title);
        $this->assertTrue($schema->integerOnly);
        $this->assertSame('Number of guests', $schema->description);
        $this->assertSame(2, $schema->default);
        $this->assertSame(1, $schema->minimum);
        $this->assertSame(10, $schema->maximum);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        NumberSchemaDefinition::fromArray(['type' => 'integer']);
    }

    public function testJsonSerializeAsInteger(): void
    {
        $schema = new NumberSchemaDefinition(
            title: 'Count',
            integerOnly: true,
        );

        $this->assertSame([
            'type' => 'integer',
            'title' => 'Count',
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeAsNumber(): void
    {
        $schema = new NumberSchemaDefinition(
            title: 'Price',
            integerOnly: false,
        );

        $this->assertSame([
            'type' => 'number',
            'title' => 'Price',
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $schema = new NumberSchemaDefinition(
            title: 'Party Size',
            integerOnly: true,
            description: 'Number of guests',
            default: 2,
            minimum: 1,
            maximum: 10,
        );

        $this->assertSame([
            'type' => 'integer',
            'title' => 'Party Size',
            'description' => 'Number of guests',
            'default' => 2,
            'minimum' => 1,
            'maximum' => 10,
        ], $schema->jsonSerialize());
    }
}
