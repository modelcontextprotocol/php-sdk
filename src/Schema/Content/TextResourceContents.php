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

namespace Mcp\Schema\Content;

use Mcp\Exception\InvalidArgumentException;

/**
 * Represents text resource contents in MCP.
 *
 * @phpstan-type TextResourceContentsData array{
 *     uri: string,
 *     mimeType?: string|null,
 *     text: string
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class TextResourceContents extends ResourceContents
{
    /**
     * @param string      $uri      the URI of the resource or sub-resource
     * @param string|null $mimeType the MIME type of the resource or sub-resource
     * @param string      $text     The text of the item. This must only be set if the item can actually be represented as text (not binary data).
     */
    public function __construct(
        string $uri,
        ?string $mimeType,
        public readonly string $text,
    ) {
        parent::__construct($uri, $mimeType);
    }

    /**
     * @param TextResourceContentsData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Missing or invalid "uri" for TextResourceContents.');
        }
        if (!isset($data['text']) || !\is_string($data['text'])) {
            throw new InvalidArgumentException('Missing or invalid "text" for TextResourceContents.');
        }

        return new self($data['uri'], $data['mimeType'] ?? null, $data['text']);
    }

    /**
     * @return TextResourceContentsData
     */
    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            ...parent::jsonSerialize(),
        ];
    }
}
