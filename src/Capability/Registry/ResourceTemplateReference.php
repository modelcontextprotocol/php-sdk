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
use Mcp\Schema\ResourceTemplate;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceTemplateReference extends ElementReference
{
    use ReflectionArgumentPreparationTrait;

    private readonly UriTemplateMatcher $uriTemplateMatcher;

    /**
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     * @param array<string, class-string|object>                       $completionProviders
     */
    public function __construct(
        public readonly ResourceTemplate $resourceTemplate,
        callable|array|string $handler,
        bool $isManual = false,
        public readonly array $completionProviders = [],
        ?UriTemplateMatcher $uriTemplateMatcher = null,
    ) {
        parent::__construct($handler, $isManual);

        $this->uriTemplateMatcher = $uriTemplateMatcher ?? new UriTemplateMatcher();
    }

    /**
     * @return array<int, string>
     */
    public function getVariableNames(): array
    {
        return $this->uriTemplateMatcher->getVariableNames($this->resourceTemplate->uriTemplate);
    }

    public function matches(string $uri): bool
    {
        return $this->uriTemplateMatcher->matches($uri, $this->resourceTemplate->uriTemplate);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractVariables(string $uri): array
    {
        return $this->uriTemplateMatcher->extractVariables($uri, $this->resourceTemplate->uriTemplate);
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContent items.
     *
     * @param mixed  $readResult the raw result from the resource handler method
     * @param string $uri        the URI of the resource that was read
     *
     * @return array<int, ResourceContents> array of ResourceContents objects
     *
     * @throws \RuntimeException If the result cannot be formatted.
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
        return (new ResourceResultFormatter())->format($readResult, $uri, $mimeType, $this->resourceTemplate->meta);
    }
}
