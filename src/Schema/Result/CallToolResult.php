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
use Mcp\Schema\Content\TextContent;
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
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class CallToolResult implements ResultInterface
{
    /**
     * Create a new CallToolResult.
     *
     * @param Content[] $content           The content of the tool result
     * @param bool      $isError           Whether the tool execution resulted in an error.  If not set, this is assumed to be false (the call was successful).
     * @param mixed[]   $structuredContent JSON content for `structuredContent`
     */
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
        public readonly ?array $structuredContent = null,
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
     * @param Content[] $content The content of the tool result
     */
    public static function success(array $content): self
    {
        return new self($content, false);
    }

    /**
     * Create a new CallToolResult with error status.
     *
     * @param Content[] $content The content of the tool result
     */
    public static function error(array $content): self
    {
        return new self($content, true);
    }

    /**
     * @param array{
     *     content: array<mixed>,
     *     isError?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['content']) || !\is_array($data['content'])) {
            throw new InvalidArgumentException('Missing or invalid "content" array in CallToolResult data.');
        }

        $contents = [];

        foreach ($data['content'] as $item) {
            $contents[] = match ($item['type'] ?? null) {
                'text' => TextContent::fromArray($item),
                'image' => ImageContent::fromArray($item),
                'audio' => AudioContent::fromArray($item),
                'resource' => EmbeddedResource::fromArray($item),
                default => throw new InvalidArgumentException(\sprintf('Invalid content type in CallToolResult data: "%s".', $item['type'] ?? null)),
            };
        }

        return new self($contents, $data['isError'] ?? false);
    }

    /**
     * @return array{
     *     content: array<mixed>,
     *     isError: bool,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'content' => $this->content,
            'isError' => $this->isError,
        ];

        if (null !== $this->structuredContent) {
            $result['structuredContent'] = $this->structuredContent;
        }

        return $result;
    }
}
