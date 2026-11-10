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

use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\StructuredContent;
use Mcp\Schema\Exception\InvalidArgumentException;
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
    public readonly mixed $structuredContent;

    private readonly bool $structuredContentPresent;

    /**
     * Create a new CallToolResult.
     *
     * @param Content[]                 $content           The content of the tool result
     * @param bool                      $isError           Whether the tool execution resulted in an error.  If not set, this is assumed to be false (the call was successful).
     * @param mixed                     $structuredContent Structured result of the call. Any JSON value — object, array,
     *                                                     string, number, boolean or null — conforming to the tool's
     *                                                     outputSchema when one is declared. Omit it or pass
     *                                                     {@see StructuredContent::Absent} to leave the field out.
     * @param array<string, mixed>|null $meta              Optional metadata
     */
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
        mixed $structuredContent = StructuredContent::Absent,
        public readonly ?array $meta = null,
    ) {
        $this->structuredContentPresent = StructuredContent::Absent !== $structuredContent;
        $this->structuredContent = $this->structuredContentPresent ? $structuredContent : null;

        foreach ($this->content as $item) {
            if (!$item instanceof Content) {
                throw new InvalidArgumentException('Content must be an array of Content objects.');
            }
        }
    }

    public function hasStructuredContent(): bool
    {
        return $this->structuredContentPresent;
    }

    /**
     * Create a new CallToolResult with success status.
     *
     * @param Content[]                 $content The content of the tool result
     * @param array<string, mixed>|null $meta    Optional metadata
     */
    public static function success(array $content, ?array $meta = null): self
    {
        return new self($content, meta: $meta);
    }

    /**
     * Create a new CallToolResult with error status.
     *
     * @param Content[]                 $content The content of the tool result
     * @param array<string, mixed>|null $meta    Optional metadata
     */
    public static function error(array $content, ?array $meta = null): self
    {
        return new self($content, true, meta: $meta);
    }

    /**
     * @param array{
     *     content: array<mixed>,
     *     isError?: bool,
     *     _meta?: array<string, mixed>,
     *     structuredContent?: mixed
     * }|array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['content']) || !\is_array($data['content'])) {
            throw new InvalidArgumentException('Missing or invalid "content" array in CallToolResult data.');
        }

        $contents = [];

        foreach ($data['content'] as $item) {
            $type = \is_array($item) ? $item['type'] ?? null : null;
            if (!\is_string($type)) {
                throw new InvalidArgumentException('Missing or invalid content "type" in CallToolResult data.');
            }

            $contents[] = match ($type) {
                'text' => TextContent::fromArray($item),
                'image' => ImageContent::fromArray($item),
                'audio' => AudioContent::fromArray($item),
                'resource' => EmbeddedResource::fromArray($item),
                'resource_link' => ResourceLink::fromArray($item),
                default => throw new InvalidArgumentException(\sprintf('Invalid content type in CallToolResult data: "%s".', $type)),
            };
        }

        if (isset($data['isError']) && !\is_bool($data['isError'])) {
            throw new InvalidArgumentException('Invalid "isError" in CallToolResult data.');
        }
        if (isset($data['_meta']) && !\is_array($data['_meta'])) {
            throw new InvalidArgumentException('Invalid "_meta" in CallToolResult data.');
        }

        return new self(
            $contents,
            $data['isError'] ?? false,
            \array_key_exists('structuredContent', $data) ? $data['structuredContent'] : StructuredContent::Absent,
            $data['_meta'] ?? null
        );
    }

    /**
     * @return array{
     *     content: list<mixed>,
     *     isError: bool,
     *     structuredContent?: mixed,
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'content' => array_values($this->content),
            'isError' => $this->isError,
        ];

        if ($this->hasStructuredContent()) {
            $result['structuredContent'] = $this->structuredContent;
        }

        if ($this->meta) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
