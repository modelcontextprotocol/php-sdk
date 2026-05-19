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
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Server;

logger()->info('Starting MCP Apps Example Server...');

$server = Server::builder()
    ->setServerInfo('MCP Apps Weather Example', '1.0.0')
    ->setLogger(logger())
    ->enableExtension(McpApps::class)
    ->addResource(
        [WeatherApp::class, 'getWeatherApp'],
        'ui://weather-app',
        'weather-app',
        description: 'Interactive weather dashboard',
        mimeType: McpApps::MIME_TYPE,
        // Empty `ui` marker on the resource descriptor flags it as an MCP App in
        // resources/list. The CSP/permissions live on the resource *content* (_meta.ui
        // in resources/read), set via UiResourceContentMeta in WeatherApp::getWeatherApp().
        meta: ['ui' => new stdClass()],
    )
    ->addTool(
        [WeatherApp::class, 'getWeather'],
        'get_weather',
        description: 'Get current weather for a city',
        meta: ['ui' => new UiToolMeta(
            resourceUri: 'ui://weather-app',
            visibility: [ToolVisibility::Model, ToolVisibility::App],
        )],
    )
    ->build();

$result = $server->run(transport());

logger()->info('Server stopped gracefully.', ['result' => $result]);

shutdown($result);
