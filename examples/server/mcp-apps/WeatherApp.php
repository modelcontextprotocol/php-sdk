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

/**
 * Example MCP Apps server exposing an interactive weather dashboard.
 *
 * The server provides:
 * - A UI resource at ui://weather-app that returns an HTML weather dashboard
 * - A tool "get_weather" linked to the UI resource, callable by both the model and the app
 */
final class WeatherApp
{
    /**
     * Returns the HTML content for the weather dashboard UI resource.
     *
     * This is registered as a resource with the ui:// URI scheme and the
     * MCP App MIME type. The host application will render it in a sandboxed iframe.
     */
    public function getWeatherApp(): TextResourceContents
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Weather Dashboard</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body {
                        font-family: var(--font-sans, system-ui, sans-serif);
                        background: var(--color-background-primary, #ffffff);
                        color: var(--color-text-primary, #1a1a1a);
                        padding: 1rem;
                    }
                    .card {
                        border: 1px solid var(--color-border-primary, #e0e0e0);
                        border-radius: var(--border-radius-md, 8px);
                        padding: 1rem;
                        margin-bottom: 1rem;
                    }
                    h1 { font-size: var(--font-heading-md-size, 1.25rem); margin-bottom: 0.5rem; }
                    .weather-data { font-size: var(--font-text-lg-size, 1.125rem); }
                    button {
                        background: var(--color-background-info, #0066cc);
                        color: var(--color-text-inverse, #ffffff);
                        border: none;
                        border-radius: var(--border-radius-sm, 4px);
                        padding: 0.5rem 1rem;
                        cursor: pointer;
                        font-size: var(--font-text-md-size, 1rem);
                    }
                    input {
                        border: 1px solid var(--color-border-primary, #e0e0e0);
                        border-radius: var(--border-radius-sm, 4px);
                        padding: 0.5rem;
                        font-size: var(--font-text-md-size, 1rem);
                        margin-right: 0.5rem;
                    }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>Weather Dashboard</h1>
                    <div>
                        <input type="text" id="city" placeholder="Enter city name" value="London">
                        <button onclick="fetchWeather()">Get Weather</button>
                    </div>
                </div>
                <div class="card" id="result" style="display:none">
                    <div class="weather-data" id="weather-data"></div>
                </div>
                <script>
                    // MCP Apps communicate with the host via window.parent.postMessage
                    // using JSON-RPC 2.0 messages.
                    let requestId = 0;
                    const pending = new Map();

                    window.addEventListener('message', (event) => {
                        try {
                            const msg = JSON.parse(event.data);
                            if (msg.id && pending.has(msg.id)) {
                                pending.get(msg.id)(msg);
                                pending.delete(msg.id);
                            }
                            // Handle tool input notification
                            if (msg.method === 'ui/notifications/tool-input') {
                                document.getElementById('city').value = msg.params.arguments.city || '';
                            }
                            // Handle tool result notification
                            if (msg.method === 'ui/notifications/tool-result') {
                                displayResult(msg.params);
                            }
                        } catch (e) { /* ignore non-JSON messages */ }
                    });

                    function sendRpc(method, params) {
                        return new Promise((resolve) => {
                            const id = ++requestId;
                            pending.set(id, resolve);
                            window.parent.postMessage(JSON.stringify({
                                jsonrpc: '2.0', id, method, params
                            }), '*');
                        });
                    }

                    async function fetchWeather() {
                        const city = document.getElementById('city').value;
                        const response = await sendRpc('tools/call', {
                            name: 'get_weather',
                            arguments: { city }
                        });
                        if (response.result) {
                            displayResult(response.result);
                        }
                    }

                    function displayResult(result) {
                        const el = document.getElementById('result');
                        const data = document.getElementById('weather-data');
                        if (result.content && result.content[0]) {
                            data.textContent = result.content[0].text;
                        }
                        el.style.display = 'block';
                    }
                </script>
            </body>
            </html>
            HTML;

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
            text: $html,
            meta: $contentMeta->toMetaArray(),
        );
    }

    /**
     * Returns weather data for a given city.
     *
     * This tool is linked to the ui://weather-app UI resource via _meta.ui.resourceUri,
     * making it callable by both the LLM agent and the rendered HTML app.
     *
     * @return string simulated weather data
     */
    public function getWeather(string $city): string
    {
        // In a real application, this would call an external weather API.
        $weather = [
            'london' => ['temp' => '15°C', 'condition' => 'Cloudy', 'humidity' => '78%'],
            'paris' => ['temp' => '18°C', 'condition' => 'Sunny', 'humidity' => '55%'],
            'tokyo' => ['temp' => '22°C', 'condition' => 'Partly Cloudy', 'humidity' => '65%'],
            'new york' => ['temp' => '12°C', 'condition' => 'Rainy', 'humidity' => '85%'],
        ];

        $key = strtolower($city);
        $data = $weather[$key] ?? ['temp' => '20°C', 'condition' => 'Clear', 'humidity' => '60%'];

        return \sprintf(
            'Weather in %s: %s, %s, Humidity: %s',
            $city,
            $data['temp'],
            $data['condition'],
            $data['humidity'],
        );
    }
}
