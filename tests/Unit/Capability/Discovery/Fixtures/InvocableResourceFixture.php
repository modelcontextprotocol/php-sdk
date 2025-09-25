<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\McpResource;

#[McpResource(uri: 'invokable://config/status', name: 'invokable_app_status')]
class InvocableResourceFixture
{
    public function __invoke(): array
    {
        return ['status' => 'OK', 'load' => rand(1, 100) / 100.0];
    }
}
