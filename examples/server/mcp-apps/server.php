#!/usr/bin/env php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Example\Server\McpApps\WeatherApp;
use Mcp\Schema\Enum\ToolVisibility;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;

logger()->info('Starting MCP Apps Example Server...');

// Build the tool UI metadata using the typed helper class.
// This links the "get_weather" tool to the "ui://weather-app" UI resource.
$toolUiMeta = new UiToolMeta(
    resourceUri: 'ui://weather-app',
    visibility: [ToolVisibility::Model->value, ToolVisibility::App->value],
);

$server = Server::builder()
    ->setServerInfo('MCP Apps Weather Example', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())

    // Register the UI resource with ui:// scheme and MCP App MIME type.
    // The _meta marks this as a UI resource in the resources/list response.
    ->addResource(
        [WeatherApp::class, 'getWeatherApp'],
        'ui://weather-app',
        'weather-app',
        description: 'Interactive weather dashboard',
        mimeType: McpApps::MIME_TYPE,
        meta: ['ui' => []],
    )

    // Register the tool linked to the UI resource via _meta.ui.
    ->addTool(
        [WeatherApp::class, 'getWeather'],
        'get_weather',
        description: 'Get current weather for a city',
        meta: $toolUiMeta->toMetaArray(),
    )

    // Advertise MCP Apps support in server capabilities.
    ->setCapabilities(new ServerCapabilities(
        tools: true,
        resources: true,
        prompts: false,
        extensions: [
            McpApps::EXTENSION_ID => McpApps::extensionCapability(),
        ],
    ))
    ->build();

/*
 * Equivalent attribute-based registration (PHP attributes require constant expressions,
 * so _meta must be specified as a raw array literal):
 *
 *     #[McpResource(
 *         uri: 'ui://weather-app',
 *         name: 'weather-app',
 *         description: 'Interactive weather dashboard',
 *         mimeType: 'text/html;profile=mcp-app',
 *         meta: ['ui' => []],
 *     )]
 *     public function getWeatherApp(): TextResourceContents { ... }
 *
 *     #[McpTool(
 *         name: 'get_weather',
 *         description: 'Get current weather for a city',
 *         meta: ['ui' => ['resourceUri' => 'ui://weather-app', 'visibility' => ['model', 'app']]],
 *     )]
 *     public function getWeather(string $city): string { ... }
 */

$result = $server->run(transport());

logger()->info('Server stopped gracefully.', ['result' => $result]);

shutdown($result);
