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

use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Tool;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ToolReference extends ElementReference
{
    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly Tool $tool,
        callable|array|string $handler,
        bool $isManual = false,
    ) {
        parent::__construct($handler, $isManual);
    }

    /**
     * Formats the result of a tool execution into an array of MCP Content items.
     *
     * - If the result is already a Content object, it's wrapped in an array.
     * - If the result is an array:
     *   - If all elements are Content objects, the array is returned as is.
     *   - If it's a mixed array (Content and non-Content items), non-Content items are
     *     individually formatted (scalars to TextContent, others to JSON TextContent).
     *   - If it's an array with no Content items, the entire array is JSON-encoded into a single TextContent.
     * - Scalars (string, int, float, bool) are wrapped in TextContent.
     * - null is represented as TextContent('(null)').
     * - Other objects are JSON-encoded and wrapped in TextContent.
     *
     * @param mixed $toolExecutionResult the raw value returned by the tool's PHP method
     *
     * @return Content[] the content items for CallToolResult
     *
     * @throws \JsonException if JSON encoding fails for non-Content array/object results
     */
    public function formatResult(mixed $toolExecutionResult): array
    {
        if ($toolExecutionResult instanceof Content) {
            return [$toolExecutionResult];
        }

        if (\is_array($toolExecutionResult)) {
            if (empty($toolExecutionResult)) {
                return [new TextContent('[]')];
            }

            $allAreContent = true;
            $hasContent = false;

            foreach ($toolExecutionResult as $item) {
                if ($item instanceof Content) {
                    $hasContent = true;
                } else {
                    $allAreContent = false;
                }
            }

            if ($allAreContent && $hasContent) {
                return $toolExecutionResult;
            }

            if ($hasContent) {
                $result = [];
                foreach ($toolExecutionResult as $item) {
                    if ($item instanceof Content) {
                        $result[] = $item;
                    } else {
                        $result = array_merge($result, $this->formatResult($item));
                    }
                }

                return $result;
            }
        }

        if (null === $toolExecutionResult) {
            return [new TextContent('(null)')];
        }

        if (\is_bool($toolExecutionResult)) {
            return [new TextContent($toolExecutionResult ? 'true' : 'false')];
        }

        if (\is_scalar($toolExecutionResult)) {
            return [new TextContent($toolExecutionResult)];
        }

        $jsonResult = json_encode(
            $toolExecutionResult,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE
        );

        return [new TextContent($jsonResult)];
    }

    /**
     * Extracts structured content from a tool result using the output schema.
     *
     * @param mixed $toolExecutionResult the raw value returned by the tool's PHP method
     *
     * @return array<string, mixed>|null the structured content, or null if not extractable
     *
     * @throws \JsonException if JSON encoding fails for non-Content array/object results
     */
    public function extractStructuredContent(mixed $toolExecutionResult): ?array
    {
        if (\is_array($toolExecutionResult)) {
            return $toolExecutionResult;
        }

        if (\is_object($toolExecutionResult) && !($toolExecutionResult instanceof Content)) {
            $jsonResult = json_encode(
                $toolExecutionResult,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE
            );

            return json_decode(
                $jsonResult, true, 512, \JSON_THROW_ON_ERROR
            );
        }

        return null;
    }
}
