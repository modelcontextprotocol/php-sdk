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

use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Enum\ProtocolVersion;
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
    ) {
        parent::__construct($handler);
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
        return (new ToolResultFormatter())->format($toolExecutionResult);
    }

    /**
     * Extracts structured content from a tool result using the output schema.
     *
     * What may be sent as `structuredContent` depends on the protocol revision in
     * use. Up to `2025-11-25` it has to be a JSON object, and `outputSchema` is
     * restricted to `type: "object"` to match. From `2026-07-28` on (SEP-2106)
     * `outputSchema` is any JSON Schema 2020-12 and `structuredContent` is any JSON
     * value conforming to it — a list included.
     *
     * @param mixed            $toolExecutionResult the raw value returned by the tool's PHP method
     * @param ?ProtocolVersion $protocolVersion     revision the result is produced for; defaults to the
     *                                              newest handshake revision, whose stricter rule is what
     *                                              every revision reachable through `initialize` requires
     *
     * @return array<array-key, mixed>|null the structured content, or null if not extractable
     *
     * @throws \JsonException if JSON encoding fails for non-Content array/object results
     */
    public function extractStructuredContent(mixed $toolExecutionResult, ?ProtocolVersion $protocolVersion = null): ?array
    {
        $objectOnly = !($protocolVersion ?? ProtocolVersion::latestHandshake())->isAtLeast(ProtocolVersion::V2026_07_28);

        if (\is_array($toolExecutionResult)) {
            // A PHP list serializes to a JSON array, which the revisions predating
            // SEP-2106 do not allow as `structuredContent` — strict clients reject
            // the whole tool call when one is sent.
            if ($objectOnly && array_is_list($toolExecutionResult)) {
                return null;
            }

            foreach ($toolExecutionResult as $item) {
                if ($item instanceof Content) {
                    // Content items are already reflected in the result's `content`
                    // array; an array holding one or more of them isn't structured
                    // data. This holds in every revision — it is a duplication rule,
                    // not a shape rule.
                    return null;
                }
            }

            return $toolExecutionResult;
        }

        if (\is_object($toolExecutionResult) && !($toolExecutionResult instanceof Content)) {
            $jsonResult = json_encode(
                $toolExecutionResult,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE
            );

            $decoded = json_decode(
                $jsonResult, true, 512, \JSON_THROW_ON_ERROR
            );

            // A plain object always encodes to a JSON object, but `JsonSerializable`
            // can hand back anything. A scalar is dropped whatever the revision
            // allows: `CallToolResult::$structuredContent` is typed `?array` and
            // cannot carry one.
            if (!\is_array($decoded)) {
                return null;
            }

            if ($objectOnly && array_is_list($decoded)) {
                return null;
            }

            return $decoded;
        }

        return null;
    }
}
