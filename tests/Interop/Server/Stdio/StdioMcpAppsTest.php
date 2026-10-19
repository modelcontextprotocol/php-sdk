<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Server\Stdio;

final class StdioMcpAppsTest extends StdioInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Read Weather UI Resource' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'ui://weather-app',
                ],
                'testName' => 'read_weather_ui',
            ],
            'Get Weather Tool Call (London)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'get_weather',
                    'toolArgs' => ['city' => 'London'],
                ],
                'testName' => 'get_weather_london',
            ],
            'Get Weather Tool Call (Tokyo)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'get_weather',
                    'toolArgs' => ['city' => 'Tokyo'],
                ],
                'testName' => 'get_weather_tokyo',
            ],
        ];
    }

    protected function getClientArgs(): array
    {
        // The Inspector CLI cannot render an App, so it only claims the
        // extension when asked; without it the tool falls back to plain text.
        return ['--advertise-apps'];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 4).'/examples/server/mcp-apps/server.php';
    }
}
