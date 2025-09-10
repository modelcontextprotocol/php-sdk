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

namespace Mcp\Server;

use Mcp\Schema\Implementation;
use Mcp\Schema\ServerCapabilities;

/**
 * Value Object holding core configuration and shared dependencies for the MCP Server instance.
 *
 * This object is typically assembled by the ServerBuilder and passed to the Server constructor.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Configuration
{
    /**
     * @param Implementation     $serverInfo      info about this MCP server application
     * @param ServerCapabilities $capabilities    capabilities of this MCP server application
     * @param int                $paginationLimit maximum number of items to return for list methods
     * @param string|null        $instructions    instructions describing how to use the server and its features
     */
    public function __construct(
        public readonly Implementation $serverInfo,
        public readonly ServerCapabilities $capabilities,
        public readonly int $paginationLimit = 50,
        public readonly ?string $instructions = null,
    ) {
    }
}
