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

use Mcp\Exception\RuntimeException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\CompletionCompleteResult;
use Psr\Container\ContainerInterface;

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

    /**
     * @var array<string, string>
     */
    private array $uriVariables;

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
     * @deprecated
     * Gets the resource template
     *
     * @return array<int, ResourceContents> array of ResourceContents objects
     */
    public function read(ContainerInterface $container, string $uri): array
    {
        $arguments = array_merge($this->uriVariables, ['uri' => $uri]);

        $result = $this->handle($container, $arguments);

        return $this->formatResult($result, $uri, $this->resourceTemplate->mimeType);
    }

    public function complete(ContainerInterface $container, string $argument, string $value): CompletionCompleteResult
    {
        $providerClassOrInstance = $this->completionProviders[$argument] ?? null;
        if (null === $providerClassOrInstance) {
            return new CompletionCompleteResult([]);
        }

        if (\is_string($providerClassOrInstance)) {
            if (!class_exists($providerClassOrInstance)) {
                throw new RuntimeException(\sprintf('Completion provider class "%s" does not exist.', $providerClassOrInstance));
            }

            $provider = $container->get($providerClassOrInstance);
        } else {
            $provider = $providerClassOrInstance;
        }

        $completions = $provider->getCompletions($value);

        $total = \count($completions);
        $hasMore = $total > 100;

        $pagedCompletions = \array_slice($completions, 0, 100);

        return new CompletionCompleteResult($pagedCompletions, $total, $hasMore);
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
        if (preg_match($this->uriTemplateRegex, $uri, $matches)) {
            $variables = [];
            foreach ($this->variableNames as $varName) {
                if (isset($matches[$varName])) {
                    $variables[$varName] = $matches[$varName];
                }
            }

            $this->uriVariables = $variables;

            return true;
        }

        return false;
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
     * - ResourceContent: Used as-is
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
        if ($readResult instanceof ResourceContents) {
            return [$readResult];
        }

        if ($readResult instanceof EmbeddedResource) {
            return [$readResult->resource];
        }

        if (\is_array($readResult)) {
            if (empty($readResult)) {
                return [new TextResourceContents($uri, 'application/json', '[]')];
            }

            $allAreResourceContents = true;
            $hasResourceContents = false;
            $allAreEmbeddedResource = true;
            $hasEmbeddedResource = false;

            foreach ($readResult as $item) {
                if ($item instanceof ResourceContents) {
                    $hasResourceContents = true;
                    $allAreEmbeddedResource = false;
                } elseif ($item instanceof EmbeddedResource) {
                    $hasEmbeddedResource = true;
                    $allAreResourceContents = false;
                } else {
                    $allAreResourceContents = false;
                    $allAreEmbeddedResource = false;
                }
            }

            if ($allAreResourceContents && $hasResourceContents) {
                return $readResult;
            }

            if ($allAreEmbeddedResource && $hasEmbeddedResource) {
                return array_map(fn ($item) => $item->resource, $readResult);
            }

            if ($hasResourceContents || $hasEmbeddedResource) {
                $result = [];
                foreach ($readResult as $item) {
                    if ($item instanceof ResourceContents) {
                        $result[] = $item;
                    } elseif ($item instanceof EmbeddedResource) {
                        $result[] = $item->resource;
                    } else {
                        $result = array_merge($result, $this->formatResult($item, $uri, $mimeType));
                    }
                }

                return $result;
            }
        }

        if (\is_string($readResult)) {
            $mimeType = $mimeType ?? $this->guessMimeTypeFromString($readResult);

            return [new TextResourceContents($uri, $mimeType, $readResult)];
        }

        if (\is_resource($readResult) && 'stream' === get_resource_type($readResult)) {
            $result = BlobResourceContents::fromStream(
                $uri,
                $readResult,
                $mimeType ?? 'application/octet-stream'
            );

            @fclose($readResult);

            return [$result];
        }

        if (\is_array($readResult) && isset($readResult['blob']) && \is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'application/octet-stream';

            return [new BlobResourceContents($uri, $mimeType, $readResult['blob'])];
        }

        if (\is_array($readResult) && isset($readResult['text']) && \is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'text/plain';

            return [new TextResourceContents($uri, $mimeType, $readResult['text'])];
        }

        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            if ($mimeType && str_contains(strtolower($mimeType), 'text')) {
                return [new TextResourceContents($uri, $mimeType, file_get_contents($readResult->getPathname()))];
            }

            return [BlobResourceContents::fromSplFileInfo($uri, $readResult, $mimeType)];
        }

        if (\is_array($readResult)) {
            if ($mimeType && (str_contains(strtolower($mimeType), 'json')
                || 'application/json' === $mimeType)) {
                try {
                    $jsonString = json_encode($readResult, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

                    return [new TextResourceContents($uri, $mimeType, $jsonString)];
                } catch (\JsonException $e) {
                    throw new RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
                }
            }

            try {
                $jsonString = json_encode($readResult, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
                $mimeType = $mimeType ?? 'application/json';

                return [new TextResourceContents($uri, $mimeType, $jsonString)];
            } catch (\JsonException $e) {
                throw new RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
            }
        }

        throw new RuntimeException("Cannot format resource read result for URI '{$uri}'. Handler method returned unhandled type: ".\gettype($readResult));
    }

    /** Guesses MIME type from string content (very basic) */
    private function guessMimeTypeFromString(string $content): string
    {
        $trimmed = ltrim($content);

        if (str_starts_with($trimmed, '<') && str_ends_with(rtrim($content), '>')) {
            if (str_contains($trimmed, '<html')) {
                return 'text/html';
            }
            if (str_contains($trimmed, '<?xml')) {
                return 'application/xml';
            }

            return 'text/plain';
        }

        if (str_starts_with($trimmed, '{') && str_ends_with(rtrim($content), '}')) {
            return 'application/json';
        }

        if (str_starts_with($trimmed, '[') && str_ends_with(rtrim($content), ']')) {
            return 'application/json';
        }

        return 'text/plain';
    }
}
