<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Attribute;

use Mcp\Capability\Attribute\McpResource;
use PHPUnit\Framework\TestCase;

class McpResourceTest extends TestCase
{
    public function testInstantiatesWithCorrectProperties(): void
    {
        // Arrange
        $uri = 'file:///test/resource';
        $name = 'test-resource-name';
        $description = 'This is a test resource description.';
        $mimeType = 'text/plain';
        $size = 1024;

        // Act
        $attribute = new McpResource(
            uri: $uri,
            name: $name,
            description: $description,
            mimeType: $mimeType,
            size: $size,
        );

        // Assert
        $this->assertSame($uri, $attribute->uri);
        $this->assertSame($name, $attribute->name);
        $this->assertSame($description, $attribute->description);
        $this->assertSame($mimeType, $attribute->mimeType);
        $this->assertSame($size, $attribute->size);
    }

    public function testInstantiatesWithNullValuesForNameAndDescription(): void
    {
        // Arrange & Act
        $attribute = new McpResource(
            uri: 'file:///test', // URI is required
            name: null,
            description: null,
            mimeType: null,
            size: null,
        );

        // Assert
        $this->assertSame('file:///test', $attribute->uri);
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->mimeType);
        $this->assertNull($attribute->size);
    }

    public function testInstantiatesWithMissingOptionalArguments(): void
    {
        // Arrange & Act
        $uri = 'file:///only-uri';
        $attribute = new McpResource(uri: $uri);

        // Assert
        $this->assertSame($uri, $attribute->uri);
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->mimeType);
        $this->assertNull($attribute->size);
    }
}
