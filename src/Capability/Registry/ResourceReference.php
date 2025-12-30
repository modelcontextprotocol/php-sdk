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
use Mcp\Schema\Resource;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceReference extends ElementReference
{
    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly Resource $resource,
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
     * @throws RuntimeException If the result cannot be formatted.
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

        $meta = $this->resource->meta;

        if (\is_array($readResult)) {
            if (empty($readResult)) {
                return [new TextResourceContents($uri, 'application/json', '[]', $meta)];
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

            return [new TextResourceContents($uri, $mimeType, $readResult, $meta)];
        }

        if (\is_resource($readResult) && 'stream' === get_resource_type($readResult)) {
            $result = BlobResourceContents::fromStream(
                $uri,
                $readResult,
                $mimeType ?? 'application/octet-stream',
                $meta
            );

            @fclose($readResult);

            return [$result];
        }

        if (\is_array($readResult) && isset($readResult['blob']) && \is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'application/octet-stream';

            return [new BlobResourceContents($uri, $mimeType, $readResult['blob'], $meta)];
        }

        if (\is_array($readResult) && isset($readResult['text']) && \is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'text/plain';

            return [new TextResourceContents($uri, $mimeType, $readResult['text'], $meta)];
        }

        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            if ($mimeType && str_contains(strtolower($mimeType), 'text')) {
                return [new TextResourceContents($uri, $mimeType, file_get_contents($readResult->getPathname()), $meta)];
            }

            return [BlobResourceContents::fromSplFileInfo($uri, $readResult, $mimeType, $meta)];
        }

        if (\is_array($readResult)) {
            if ($mimeType && (str_contains(strtolower($mimeType), 'json')
                || 'application/json' === $mimeType)) {
                try {
                    $jsonString = json_encode($readResult, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

                    return [new TextResourceContents($uri, $mimeType, $jsonString, $meta)];
                } catch (\JsonException $e) {
                    throw new RuntimeException(\sprintf('Failed to encode array as JSON for URI "%s": %s', $uri, $e->getMessage()));
                }
            }

            try {
                $jsonString = json_encode($readResult, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
                $mimeType = $mimeType ?? 'application/json';

                return [new TextResourceContents($uri, $mimeType, $jsonString, $meta)];
            } catch (\JsonException $e) {
                throw new RuntimeException(\sprintf('Failed to encode array as JSON for URI "%s": %s', $uri, $e->getMessage()));
            }
        }

        throw new RuntimeException(\sprintf('Cannot format resource read result for URI "%s". Handler method returned unhandled type: ', $uri).\gettype($readResult));
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
