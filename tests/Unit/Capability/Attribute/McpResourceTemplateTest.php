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

use Mcp\Capability\Attribute\McpResourceTemplate;
use PHPUnit\Framework\TestCase;

class McpResourceTemplateTest extends TestCase
{
    public function testInstantiatesWithCorrectProperties(): void
    {
        // Arrange
        $uriTemplate = 'file:///{path}/data';
        $name = 'test-template-name';
        $description = 'This is a test template description.';
        $mimeType = 'application/json';

        // Act
        $attribute = new McpResourceTemplate(
            uriTemplate: $uriTemplate,
            name: $name,
            description: $description,
            mimeType: $mimeType,
        );

        // Assert
        $this->assertSame($uriTemplate, $attribute->uriTemplate);
        $this->assertSame($name, $attribute->name);
        $this->assertSame($description, $attribute->description);
        $this->assertSame($mimeType, $attribute->mimeType);
    }

    public function testInstantiatesWithNullValuesForNameAndDescription(): void
    {
        // Arrange & Act
        $attribute = new McpResourceTemplate(
            uriTemplate: 'test://{id}', // uriTemplate is required
            name: null,
            description: null,
            mimeType: null,
        );

        // Assert
        $this->assertSame('test://{id}', $attribute->uriTemplate);
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->mimeType);
    }

    public function testInstantiatesWithMissingOptionalArguments(): void
    {
        // Arrange & Act
        $uriTemplate = 'tmpl://{key}';
        $attribute = new McpResourceTemplate(uriTemplate: $uriTemplate);

        // Assert
        $this->assertSame($uriTemplate, $attribute->uriTemplate);
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->mimeType);
    }
}
