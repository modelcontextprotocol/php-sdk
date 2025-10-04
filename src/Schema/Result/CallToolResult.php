<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\StructuredContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's response to a tool call.
 *
 * Any errors that originate from the tool SHOULD be reported inside the result
 * object, with `isError` set to true, _not_ as an MCP protocol-level error
 * response. Otherwise, the LLM would not be able to see that an error occurred
 * and self-correct.
 *
 * However, any errors in _finding_ the tool, an error indicating that the
 * server does not support tool calls, or any other exceptional conditions,
 * should be reported as an MCP error response.
 *
 * @phpstan-import-type TextContentData from TextContent
 * @phpstan-import-type ImageContentData from ImageContent
 * @phpstan-import-type AudioContentData from AudioContent
 * @phpstan-import-type EmbeddedResourceData from EmbeddedResource
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class CallToolResult implements ResultInterface
{
    /**
     * Create a new CallToolResult.
     *
     * @param array<TextContent|ImageContent|AudioContent|EmbeddedResource> $content The content of the tool result
     * @param bool                                                          $isError Whether the tool execution resulted in an error.  If not set, this is assumed to be false (the call was successful).
     */
    public function __construct(
        public readonly array $content,
        public readonly ?StructuredContent $structuredContent = null,
        public readonly bool $isError = false,
    ) {
        foreach ($this->content as $item) {
            if (!$item instanceof Content) {
                throw new InvalidArgumentException('Content must be an array of Content objects.');
            }
        }
    }

    /**
     * Create a new CallToolResult with success status.
     *
     * @param array<TextContent|ImageContent|AudioContent|EmbeddedResource> $content The content of the tool result
     */
    public static function success(array $content, ?StructuredContent $structuredContent = null): self
    {
        return new self($content, $structuredContent, false);
    }

    /**
     * Create a new CallToolResult with error status.
     *
     * @param array<TextContent|ImageContent|AudioContent|EmbeddedResource> $content The content of the tool result
     */
    public static function error(array $content, ?StructuredContent $structuredContent = null): self
    {
        return new self($content, $structuredContent, true);
    }

    /**
     * @param array<int, TextContent|ImageContent|AudioContent|EmbeddedResource|StructuredContent> $data
     */
    public static function fromArray(array $data): self
    {
        $contents = [];
        $structuredContent = null;
        $isError = false;

        foreach ($data as $item) {
            if (!$item instanceof Content) {
                throw new InvalidArgumentException('Provided array must be an array of Content objects.');
            }
            if ('structured' === $item->type) {
                $structuredContent = $item;
                continue;
            }
            $contents[] = match ($item->type) {
                // TODO this should be enum, also `resource_link` missing.
                // We shouldn't rely on user input for type and just use instanceof instead
                'text', 'audio', 'image', 'resource' => $item,
                default => throw new InvalidArgumentException(\sprintf('Invalid content type in CallToolResult data: "%s".', $item['type'] ?? null)),
            };

            if ('text' === $item->type && $item instanceof TextContent) {
                $isError = $item->isError;
            }
        }

        return new self($contents, $structuredContent, $isError);
    }

    /**
     * @return array{
     *     content: array<TextContentData|ImageContentData|AudioContentData|EmbeddedResourceData>,
     *     structuredContent?: mixed[],
     *     isError: bool,
     * }
     */
    public function jsonSerialize(): array
    {
        $result['content'] = [];
        foreach ($this->content as $item) {
            $result['content'][] = $item->jsonSerialize();
        }

        $result['isError'] = $this->isError;

        if ($this->structuredContent) {
            $result['structuredContent'] = $this->structuredContent->jsonSerialize();
        }

        return $result;
    }
}
