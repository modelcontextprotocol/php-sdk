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
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Tool;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ToolReference extends ElementReference
{
    use ReflectionArgumentPreparationTrait;

    /**
     * @param callable|array{0: class-string|object, 1: string}|string $handler
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
        return (new ToolResultFormatter())->format($toolExecutionResult);
    }
}
