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

use Mcp\Schema\Annotations;

/**
 * Marks a PHP class definition as representing an MCP Resource Template.
 * This is informational, used for 'resources/templates/list'.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class McpResourceTemplate
{
    /**
     * @param string                $uriTemplate the URI template string (RFC 6570)
     * @param ?string               $name        A human-readable name for the template type.  If null, a default might be generated from the method name.
     * @param ?string               $description Optional description. Defaults to class DocBlock summary.
     * @param ?string               $mimeType    optional default MIME type for matching resources
     * @param ?Annotations          $annotations optional annotations describing the resource template
     * @param ?array<string, mixed> $meta        Optional metadata
     */
    public function __construct(
        public string $uriTemplate,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?Annotations $annotations = null,
        public ?array $meta = null,
    ) {
    }
}
