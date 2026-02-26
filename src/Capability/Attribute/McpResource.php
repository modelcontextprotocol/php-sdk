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
use Mcp\Schema\Icon;

/**
 * Marks a PHP class as representing or handling a specific MCP Resource instance.
 * Used primarily for the 'resources/list' discovery.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class McpResource
{
    /**
     * @param string                $uri         The specific URI identifying this resource instance. Must be unique within the server.
     * @param ?string               $name        A human-readable name for this resource. If null, a default might be generated from the method name.
     * @param ?string               $description An optional description of the resource. Defaults to class DocBlock summary.
     * @param ?string               $mimeType    the MIME type, if known and constant for this resource
     * @param ?int                  $size        the size in bytes, if known and constant
     * @param Annotations|null      $annotations optional annotations describing the resource
     * @param ?Icon[]               $icons       Optional list of icon URLs representing the resource
     * @param ?array<string, mixed> $meta        Optional metadata
     */
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?Annotations $annotations = null,
        public ?array $icons = null,
        public ?array $meta = null,
    ) {
    }
}
