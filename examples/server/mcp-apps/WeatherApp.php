<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\McpApps;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Server\RequestContext;

final class WeatherApp
{
    public function getWeatherApp(): TextResourceContents
    {
        $contentMeta = new UiResourceContentMeta(
            csp: new UiResourceCsp(
                connectDomains: ['https://api.weather.example.com'],
            ),
            permissions: new UiResourcePermissions(
                geolocation: true,
            ),
            prefersBorder: true,
        );

        return new TextResourceContents(
            uri: 'ui://weather-app',
            mimeType: McpApps::MIME_TYPE,
            text: file_get_contents(__DIR__.'/weather-app.html'),
            meta: ['ui' => $contentMeta],
        );
    }

    public function getWeather(string $city, RequestContext $context): string
    {
        $weather = [
            'london' => ['temp' => '15°C', 'condition' => 'Cloudy', 'humidity' => '78%'],
            'paris' => ['temp' => '18°C', 'condition' => 'Sunny', 'humidity' => '55%'],
            'tokyo' => ['temp' => '22°C', 'condition' => 'Partly Cloudy', 'humidity' => '65%'],
            'new york' => ['temp' => '12°C', 'condition' => 'Rainy', 'humidity' => '85%'],
            'lagos' => ['temp' => '30°C', 'condition' => 'Sunny', 'humidity' => '82%'],
            'stockholm' => ['temp' => '4°C', 'condition' => 'Cloudy', 'humidity' => '70%'],
            'berlin' => ['temp' => '9°C', 'condition' => 'Partly Cloudy', 'humidity' => '68%'],
            'sydney' => ['temp' => '26°C', 'condition' => 'Sunny', 'humidity' => '60%'],
            'buenos aires' => ['temp' => '24°C', 'condition' => 'Rainy', 'humidity' => '80%'],
        ];

        $key = strtolower($city);
        $data = $weather[$key] ?? ['temp' => '20°C', 'condition' => 'Clear', 'humidity' => '60%'];

        $text = \sprintf(
            'Weather in %s: %s, %s, Humidity: %s',
            $city,
            $data['temp'],
            $data['condition'],
            $data['humidity'],
        );

        // Hosts without MCP Apps support only ever see this text; the ones that
        // negotiated the extension render the ui://weather-app view on top of it.
        if (!$context->getClientGateway()->supportsExtension(McpApps::EXTENSION_ID)) {
            return $text.' (text-only, host does not support MCP Apps)';
        }

        return $text;
    }
}
