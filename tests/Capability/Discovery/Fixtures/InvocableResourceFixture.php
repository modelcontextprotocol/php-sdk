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

namespace Mcp\Tests\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\McpResource;

#[McpResource(uri: 'invokable://config/status', name: 'invokable_app_status')]
class InvocableResourceFixture
{
    public function __invoke(): array
    {
        return ['status' => 'OK', 'load' => random_int(1, 100) / 100.0];
    }
}
