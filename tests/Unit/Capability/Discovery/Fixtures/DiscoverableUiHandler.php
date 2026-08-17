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
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiToolMeta;

/**
 * An MCP App declared purely through attributes: a `ui://` resource flagged with the
 * `_meta.ui` marker and a tool linked to it via `UiToolMeta`.
 */
class DiscoverableUiHandler
{
    #[McpResource(
        uri: 'ui://widget/clock',
        name: 'clock_widget',
        mimeType: McpApps::MIME_TYPE,
        meta: ['ui' => new \stdClass()],
    )]
    public function widget(): TextResourceContents
    {
        return new TextResourceContents(
            uri: 'ui://widget/clock',
            mimeType: McpApps::MIME_TYPE,
            text: '<html></html>',
            meta: ['ui' => new UiResourceContentMeta(csp: new UiResourceCsp(connectDomains: ['https://time.example.com']))],
        );
    }

    #[McpTool(
        name: 'show_clock',
        meta: ['ui' => new UiToolMeta(resourceUri: 'ui://widget/clock', visibility: [ToolVisibility::App])],
    )]
    public function showClock(string $timezone): string
    {
        return $timezone;
    }
}
