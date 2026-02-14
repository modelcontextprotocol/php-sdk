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
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use PHPUnit\Framework\TestCase;

final class StringSchemaDefinitionTest extends TestCase
{
    public function testConstructorWithMinimalParams(): void
    {
        $schema = new StringSchemaDefinition('Name');

        $this->assertSame('Name', $schema->title);
        $this->assertNull($schema->description);
        $this->assertNull($schema->default);
        $this->assertNull($schema->format);
        $this->assertNull($schema->minLength);
        $this->assertNull($schema->maxLength);
    }

    public function testConstructorWithAllParams(): void
    {
        $schema = new StringSchemaDefinition(
            title: 'Email Address',
            description: 'Your primary email',
            default: 'user@example.com',
            format: 'email',
            minLength: 5,
            maxLength: 100,
        );

        $this->assertSame('Email Address', $schema->title);
        $this->assertSame('Your primary email', $schema->description);
        $this->assertSame('user@example.com', $schema->default);
        $this->assertSame('email', $schema->format);
        $this->assertSame(5, $schema->minLength);
        $this->assertSame(100, $schema->maxLength);
    }

    public function testConstructorWithValidFormats(): void
    {
        foreach (['date', 'date-time', 'email', 'uri'] as $format) {
            $schema = new StringSchemaDefinition('Test', format: $format);
            $this->assertSame($format, $schema->format);
        }
    }

    public function testConstructorWithInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid format "invalid"');

        new StringSchemaDefinition('Test', format: 'invalid');
    }

    public function testConstructorWithNegativeMinLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minLength must be non-negative');

        new StringSchemaDefinition('Test', minLength: -1);
    }

    public function testConstructorWithNegativeMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxLength must be non-negative');

        new StringSchemaDefinition('Test', maxLength: -1);
    }

    public function testConstructorWithMinLengthGreaterThanMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minLength cannot be greater than maxLength');

        new StringSchemaDefinition('Test', minLength: 10, maxLength: 5);
    }

    public function testFromArrayWithMinimalParams(): void
    {
        $schema = StringSchemaDefinition::fromArray(['title' => 'Name']);

        $this->assertSame('Name', $schema->title);
    }

    public function testFromArrayWithAllParams(): void
    {
        $schema = StringSchemaDefinition::fromArray([
            'title' => 'Email Address',
            'description' => 'Your primary email',
            'default' => 'user@example.com',
            'format' => 'email',
            'minLength' => 5,
            'maxLength' => 100,
        ]);

        $this->assertSame('Email Address', $schema->title);
        $this->assertSame('Your primary email', $schema->description);
        $this->assertSame('user@example.com', $schema->default);
        $this->assertSame('email', $schema->format);
        $this->assertSame(5, $schema->minLength);
        $this->assertSame(100, $schema->maxLength);
    }

    public function testFromArrayWithMissingTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "title"');

        /* @phpstan-ignore argument.type */
        StringSchemaDefinition::fromArray([]);
    }

    public function testJsonSerializeWithMinimalParams(): void
    {
        $schema = new StringSchemaDefinition('Name');

        $this->assertSame([
            'type' => 'string',
            'title' => 'Name',
        ], $schema->jsonSerialize());
    }

    public function testJsonSerializeWithAllParams(): void
    {
        $schema = new StringSchemaDefinition(
            title: 'Email Address',
            description: 'Your primary email',
            default: 'user@example.com',
            format: 'email',
            minLength: 5,
            maxLength: 100,
        );

        $this->assertSame([
            'type' => 'string',
            'title' => 'Email Address',
            'description' => 'Your primary email',
            'default' => 'user@example.com',
            'format' => 'email',
            'minLength' => 5,
            'maxLength' => 100,
        ], $schema->jsonSerialize());
    }
}
