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

namespace Mcp\Capability\Resource;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ResourceReadResult
{
    public function __construct(
        public readonly string $result,
        public readonly string $uri,

        /**
         * @var "text"|"blob"
         */
        public readonly string $type = 'text',
        public readonly string $mimeType = 'text/plain',
    ) {
    }
}
