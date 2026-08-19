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
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class BooleanSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $schema = new BooleanSchemaDefinition('Confirm');

        $this->assertSame('Confirm', $schema->title);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
    }

    public function testConstructorWithAllParams(): void
    {
        $schema = new BooleanSchemaDefinition(
            title: 'Confirmation',
            description: 'Do you confirm this action?',
            default: false,
        );

        $this->assertSame('Confirmation', $schema->title);
        $this->assertSame('Do you confirm this action?', $schema->description);
        $this->assertFalse($schema->default);
    }

    public function testConstructorWithTrueDefault(): void
    {
        $schema = new BooleanSchemaDefinition(
            title: 'Subscribe',
            default: true,
        );

        $this->assertTrue($schema->default);
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $schema = BooleanSchemaDefinition::fromArray(['title' => 'Confirm']);

        $this->assertSame('Confirm', $schema->title);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
    }

    public function testFromArrayWithAllParams(): void
    {
        $schema = BooleanSchemaDefinition::fromArray([
            'title' => 'Confirmation',
            'description' => 'Do you confirm this action?',
            'default' => true,
        ]);

        $this->assertSame('Confirmation', $schema->title);
        $this->assertSame('Do you confirm this action?', $schema->description);
        $this->assertTrue($schema->default);
    }

    public function testFromArrayWithoutTitleIsAccepted(): void
    {
        // `title` is optional in the specification, so a server that omits it
        // must still be readable.
        $schema = BooleanSchemaDefinition::fromArray([]);

        $this->assertNull($schema->title);
        $this->assertArrayNotHasKey('title', $schema->jsonSerialize());
    }

    public function testFromArrayRejectsNonStringTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "title" for boolean schema definition.');

        /* @phpstan-ignore argument.type */
        BooleanSchemaDefinition::fromArray(['title' => 42]);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $schema = new BooleanSchemaDefinition('Confirm');

        $this->assertSame([
            'type' => 'boolean',
            'title' => 'Confirm',
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $schema = new BooleanSchemaDefinition(
            title: 'Confirmation',
            description: 'Do you confirm this action?',
            default: false,
        );

        $this->assertSame([
            'type' => 'boolean',
            'title' => 'Confirmation',
            'description' => 'Do you confirm this action?',
            'default' => false,
        ], $schema->jsonSerialize());
    }
}
