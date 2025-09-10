<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Attribute;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;

class McpToolTest extends TestCase
{
    public function testInstantiatesWithCorrectProperties(): void
    {
        // Arrange
        $name = 'test-tool-name';
        $description = 'This is a test description.';

        // Act
        $attribute = new McpTool(name: $name, description: $description);

        // Assert
        $this->assertSame($name, $attribute->name);
        $this->assertSame($description, $attribute->description);
    }

    public function testInstantiatesWithNullValuesForNameAndDescription(): void
    {
        // Arrange & Act
        $attribute = new McpTool(name: null, description: null);

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
    }

    public function testInstantiatesWithMissingOptionalArguments(): void
    {
        // Arrange & Act
        $attribute = new McpTool(); // Use default constructor values

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
    }
}
