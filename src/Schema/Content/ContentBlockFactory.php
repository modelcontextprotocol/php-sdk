<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

use Mcp\Exception\InvalidArgumentException;

/**
 * Decodes ContentBlock data into the matching implementation.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ContentBlockFactory
{
    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ContentBlock
    {
        return match ($data['type'] ?? null) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ResourceLink::TYPE => ResourceLink::fromArray($data),
            EmbeddedResource::TYPE => EmbeddedResource::fromArray($data),
            default => throw new InvalidArgumentException('Unsupported ContentBlock data.'),
        };
    }
}
