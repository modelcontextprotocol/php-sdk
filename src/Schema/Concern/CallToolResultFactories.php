<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Schema\Content\ContentBlock;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait CallToolResultFactories
{
    /**
     * @param array<ContentBlock>       $content
     * @param array<string, mixed>|null $meta
     */
    public static function success(array $content, ?array $meta = null): self
    {
        return new self(content: $content, isError: false, meta: $meta);
    }

    /**
     * @param array<ContentBlock>       $content
     * @param array<string, mixed>|null $meta
     */
    public static function error(array $content, ?array $meta = null): self
    {
        return new self(content: $content, isError: true, meta: $meta);
    }
}
