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

use Mcp\Capability\Attribute\McpPrompt;
use PHPUnit\Framework\TestCase;

class McpPromptTest extends TestCase
{
    public function testInstantiatesWithNameAndDescription(): void
    {
        // Arrange
        $name = 'test-prompt-name';
        $description = 'This is a test prompt description.';

        // Act
        $attribute = new McpPrompt(name: $name, description: $description);

        // Assert
        $this->assertSame($name, $attribute->name);
        $this->assertSame($description, $attribute->description);
    }

    public function testInstantiatesWithNullValuesForNameAndDescription(): void
    {
        // Arrange & Act
        $attribute = new McpPrompt(name: null, description: null);

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
    }

    public function testInstantiatesWithMissingOptionalArguments(): void
    {
        // Arrange & Act
        $attribute = new McpPrompt(); // Use default constructor values

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
    }
}
