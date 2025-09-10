<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's response to a resources/read request from the client.
 *
 * @phpstan-import-type TextResourceContentsData from TextResourceContents
 * @phpstan-import-type BlobResourceContentsData from BlobResourceContents
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ReadResourceResult implements ResultInterface
{
    /**
     * Create a new ReadResourceResult.
     *
     * @param ResourceContents[] $contents The contents of the resource
     */
    public function __construct(
        public readonly array $contents,
    ) {
    }

    /**
     * @param array{
     *     contents: array<TextResourceContentsData|BlobResourceContentsData>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['contents']) || !\is_array($data['contents'])) {
            throw new InvalidArgumentException('Missing or invalid "contents" array in ReadResourceResult data.');
        }

        $contents = [];
        foreach ($data['contents'] as $content) {
            if (isset($content['text'])) {
                $contents[] = TextResourceContents::fromArray($content);
            } elseif (isset($content['blob'])) {
                $contents[] = BlobResourceContents::fromArray($content);
            } else {
                throw new InvalidArgumentException('Invalid content type in ReadResourceResult data: '.json_encode($content));
            }
        }

        return new self($contents);
    }

    /**
     * @return array{
     *     contents: array<BlobResourceContents|TextResourceContents>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'contents' => $this->contents,
        ];
    }
}
