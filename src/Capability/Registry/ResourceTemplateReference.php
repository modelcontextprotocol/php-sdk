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
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceTemplateReference extends ElementReference
{
    /**
     * @var array<int, string>
     */
    private array $variableNames;

    private string $uriTemplateRegex;

    /**
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function __construct(
        public readonly ResourceTemplate $resourceTemplate,
        callable|array|string $handler,
        bool $isManual = false,
        public readonly array $completionProviders = [],
    ) {
        parent::__construct($handler, $isManual);

        $this->compileTemplate();
    }

    /**
     * @return array<int, string>
     */
    public function getVariableNames(): array
    {
        return $this->variableNames;
    }

    public function matches(string $uri): bool
    {
        return 1 === preg_match($this->uriTemplateRegex, $uri);
    }

    /** @return array<string, mixed> */
    public function extractVariables(string $uri): array
    {
        $matches = [];

        preg_match($this->uriTemplateRegex, $uri, $matches);

        return array_filter($matches, fn ($key) => \in_array($key, $this->variableNames), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContent items.
     *
     * @param mixed  $readResult the raw result from the resource handler method
     * @param string $uri        the URI of the resource that was read
     *
     * @return array<int, ResourceContents> array of ResourceContents objects
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

    private function compileTemplate(): void
    {
        $this->variableNames = [];
        $regexParts = [];

        $segments = preg_split('/(\{\w+\})/', $this->resourceTemplate->uriTemplate, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);

        foreach ($segments as $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $matches)) {
                $varName = $matches[1];
                $this->variableNames[] = $varName;
                $regexParts[] = '(?P<'.$varName.'>[^/]+)';
            } else {
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $this->uriTemplateRegex = '#^'.implode('', $regexParts).'$#';
    }
}
