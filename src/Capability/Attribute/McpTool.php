<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Attribute;

use Mcp\Schema\ToolAnnotations;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class McpTool
{
    /**
     * @param string|null               $name         The name of the tool (defaults to the method name)
     * @param string|null               $description  The description of the tool (defaults to the DocBlock/inferred)
     * @param ToolAnnotations|null      $annotations  Optional annotations describing tool behavior
     * @param array<string, mixed>|null $outputSchema Optional JSON Schema object (as a PHP array) defining the expected output structure
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?ToolAnnotations $annotations = null,
        public ?array $outputSchema = null,
    ) {
    }
}
