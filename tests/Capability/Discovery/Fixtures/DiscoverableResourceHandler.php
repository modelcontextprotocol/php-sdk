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
use Mcp\Schema\Annotations;

class DiscoverableResourceHandler
{
    /**
     * Provides the application's current version.
     *
     * @return string the version string
     */
    #[McpResource(
        uri: 'app://info/version',
        name: 'app_version',
        description: 'The current version of the application.',
        mimeType: 'text/plain',
        size: 10
    )]
    public function getAppVersion(): string
    {
        return '1.2.3-discovered';
    }

    #[McpResource(
        uri: 'config://settings/ui',
        name: 'ui_settings_discovered',
        mimeType: 'application/json',
        annotations: new Annotations(priority: 0.5)
    )]
    public function getUiSettings(): array
    {
        return ['theme' => 'dark', 'fontSize' => 14];
    }

    public function someOtherMethod(): void
    {
    }
}
