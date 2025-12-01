<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Capability\Formatter\ResourceResultFormatter;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Resource;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceReference extends ElementReference
{
    use ReflectionArgumentPreparationTrait;

    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly Resource $schema,
        callable|array|string $handler,
        bool $isManual = false,
    ) {
        parent::__construct($handler, $isManual);
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContent items.
     *
     * @param mixed   $readResult the raw result from the resource handler method
     * @param string  $uri        the URI of the resource that was read
     * @param ?string $mimeType   the MIME type from the ResourceDefinition
     *
     * @return ResourceContents[] array of ResourceContents objects
     *
     * Supported result types:
     * - ResourceContents: Used as-is
     * - EmbeddedResource: Resource is extracted from the EmbeddedResource
     * - string: Converted to text content with guessed or provided MIME type
     * - stream resource: Read and converted to blob with provided MIME type
     * - array with 'blob' key: Used as blob content
     * - array with 'text' key: Used as text content
     * - SplFileInfo: Read and converted to blob
     * - array: Converted to JSON if MIME type is application/json or contains 'json'
     *          For other MIME types, will try to convert to JSON with a warning
     */
    public function formatResult(mixed $readResult, string $uri, ?string $mimeType = null): array
    {
        return (new ResourceResultFormatter())->format($readResult, $uri, $mimeType, $this->schema->meta);
    }
}
