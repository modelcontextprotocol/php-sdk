<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector\Http;

/**
 * Snapshots the MCP Apps example as the Inspector sees it.
 *
 * What the snapshots are actually pinning is the extension's metadata surviving
 * registration: the `ui://` resource carrying `text/html;profile=mcp-app` and
 * the `ui` marker in its `_meta`, and the tool carrying the `UiToolMeta` that
 * links it to that resource. Those are the two halves a host needs to render an
 * app, and both travel in `_meta`, where a silent drop would otherwise go
 * unnoticed until a host failed to render anything.
 */
final class HttpMcpAppsTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Read the UI resource' => [
                'method' => 'resources/read',
                'options' => ['uri' => 'ui://weather-app'],
                'testName' => 'weather_app',
            ],
            'Call the linked tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'get_weather',
                    'toolArgs' => ['city' => 'london'],
                ],
                'testName' => 'get_weather',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/server/mcp-apps/server.php';
    }
}
