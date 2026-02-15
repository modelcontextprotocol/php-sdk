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
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class ElicitationSchemaTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $properties = [
            'name' => new StringSchemaDefinition('Name'),
        ];

        $schema = new ElicitationSchema($properties);

        $this->assertCount(1, $schema->properties);
        $this->assertSame([], $schema->required);
    }

    public function testConstructorWithRequiredFields(): void
    {
        $properties = [
            'name' => new StringSchemaDefinition('Name'),
            'email' => new StringSchemaDefinition('Email'),
        ];

        $schema = new ElicitationSchema($properties, ['name']);

        $this->assertCount(2, $schema->properties);
        $this->assertSame(['name'], $schema->required);
    }

    public function testConstructorWithMultipleTypes(): void
    {
        $properties = [
            'name' => new StringSchemaDefinition('Name'),
            'age' => new NumberSchemaDefinition('Age', integerOnly: true),
            'subscribe' => new BooleanSchemaDefinition('Subscribe'),
            'rating' => new EnumSchemaDefinition('Rating', ['1', '2', '3', '4', '5']),
        ];

        $schema = new ElicitationSchema($properties, ['name', 'age']);

        $this->assertCount(4, $schema->properties);
        $this->assertInstanceOf(StringSchemaDefinition::class, $schema->properties['name']);
        $this->assertInstanceOf(NumberSchemaDefinition::class, $schema->properties['age']);
        $this->assertInstanceOf(BooleanSchemaDefinition::class, $schema->properties['subscribe']);
        $this->assertInstanceOf(EnumSchemaDefinition::class, $schema->properties['rating']);
    }

    public function testConstructorWithEmptyProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('properties array must not be empty');

        new ElicitationSchema([]);
    }

    public function testConstructorWithInvalidRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required property "unknown" is not defined in properties');

        new ElicitationSchema(
            ['name' => new StringSchemaDefinition('Name')],
            ['unknown'],
        );
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $schema = ElicitationSchema::fromArray([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]);

        $this->assertCount(1, $schema->properties);
        $this->assertInstanceOf(StringSchemaDefinition::class, $schema->properties['name']);
    }

    public function testFromArrayWithExplicitObjectType(): void
    {
        $schema = ElicitationSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]);

        $this->assertCount(1, $schema->properties);
    }

    public function testFromArrayWithInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ElicitationSchema type must be "object"');

        ElicitationSchema::fromArray([
            'type' => 'array',
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]);
    }

    public function testFromArrayWithMissingProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "properties"');

        /* @phpstan-ignore argument.type */
        ElicitationSchema::fromArray([]);
    }

    public function testFromArrayWithRequiredFields(): void
    {
        $schema = ElicitationSchema::fromArray([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'email' => ['type' => 'string', 'title' => 'Email', 'format' => 'email'],
            ],
            'required' => ['name'],
        ]);

        $this->assertSame(['name'], $schema->required);
    }

    public function testFromArrayWithMultipleTypes(): void
    {
        $schema = ElicitationSchema::fromArray([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'age' => ['type' => 'integer', 'title' => 'Age', 'minimum' => 0],
                'confirm' => ['type' => 'boolean', 'title' => 'Confirm'],
                'rating' => ['type' => 'string', 'title' => 'Rating', 'enum' => ['1', '2', '3']],
            ],
        ]);

        $this->assertInstanceOf(StringSchemaDefinition::class, $schema->properties['name']);
        $this->assertInstanceOf(NumberSchemaDefinition::class, $schema->properties['age']);
        $this->assertInstanceOf(BooleanSchemaDefinition::class, $schema->properties['confirm']);
        $this->assertInstanceOf(EnumSchemaDefinition::class, $schema->properties['rating']);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $schema = new ElicitationSchema([
            'name' => new StringSchemaDefinition('Name'),
        ]);

        $result = $schema->jsonSerialize();

        $this->assertSame('object', $result['type']);
        $this->assertArrayHasKey('name', $result['properties']);
        $this->assertSame('string', $result['properties']['name']['type']);
        $this->assertArrayNotHasKey('required', $result);
    }

    public function testJsonSerializeWithRequiredFields(): void
    {
        $schema = new ElicitationSchema(
            [
                'name' => new StringSchemaDefinition('Name'),
                'email' => new StringSchemaDefinition('Email'),
            ],
            ['name'],
        );

        $result = $schema->jsonSerialize();

        $this->assertSame(['name'], $result['required']);
    }

    public function testJsonSerializeWithFullSchema(): void
    {
        $schema = new ElicitationSchema(
            [
                'name' => new StringSchemaDefinition('Full Name', description: 'Your full name'),
                'age' => new NumberSchemaDefinition('Age', integerOnly: true, minimum: 0, maximum: 150),
                'subscribe' => new BooleanSchemaDefinition('Subscribe', default: false),
            ],
            ['name', 'age'],
        );

        $result = $schema->jsonSerialize();

        $this->assertSame('object', $result['type']);
        $this->assertCount(3, $result['properties']);
        $this->assertSame(['name', 'age'], $result['required']);

        $this->assertSame('string', $result['properties']['name']['type']);
        $this->assertSame('Full Name', $result['properties']['name']['title']);
        $this->assertSame('Your full name', $result['properties']['name']['description']);

        $this->assertSame('integer', $result['properties']['age']['type']);
        $this->assertSame(0, $result['properties']['age']['minimum']);
        $this->assertSame(150, $result['properties']['age']['maximum']);

        $this->assertSame('boolean', $result['properties']['subscribe']['type']);
        $this->assertFalse($result['properties']['subscribe']['default']);
    }
}
