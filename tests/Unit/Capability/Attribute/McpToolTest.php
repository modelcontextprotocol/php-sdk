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
        $attribute = new McpTool(name: null, description: null, outputSchema: null);

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->outputSchema);
    }

    public function testInstantiatesWithMissingOptionalArguments(): void
    {
        // Arrange & Act
        $attribute = new McpTool(); // Use default constructor values

        // Assert
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->outputSchema);
    }

    public function testInstantiatesWithOutputSchema(): void
    {
        // Arrange
        $name = 'test-tool-name';
        $description = 'This is a test description.';
        $outputSchema = [
            'type' => 'object',
            'properties' => [
                'result' => [
                    'type' => 'string',
                    'description' => 'The result of the operation',
                ],
            ],
            'required' => ['result'],
        ];

        // Act
        $attribute = new McpTool(name: $name, description: $description, outputSchema: $outputSchema);

        // Assert
        $this->assertSame($name, $attribute->name);
        $this->assertSame($description, $attribute->description);
        $this->assertSame($outputSchema, $attribute->outputSchema);
    }
}
