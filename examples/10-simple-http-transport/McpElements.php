<?php

namespace Mcp\Example\HttpTransportExample;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;

class McpElements
{
    public function __construct() {}

    /**
     * Get the current server time
     */
    #[McpTool(name: 'current_time')]
    public function getCurrentTime(string $format = 'Y-m-d H:i:s'): string
    {
        try {
            return date($format);
        } catch (\Exception $e) {
            return date('Y-m-d H:i:s'); // fallback
        }
    }

    /**
     * Calculate simple math operations
     */
    #[McpTool(name: 'calculate')]
    public function calculate(float $a, float $b, string $operation): float|string
    {
        return match (strtolower($operation)) {
            'add', '+' => $a + $b,
            'subtract', '-' => $a - $b,
            'multiply', '*' => $a * $b,
            'divide', '/' => $b != 0 ? $a / $b : 'Error: Division by zero',
            default => 'Error: Unknown operation. Use: add, subtract, multiply, divide'
        };
    }

    /**
     * Server information resource
     */
    #[McpResource(
        uri: 'info://server/status',
        name: 'server_status',
        description: 'Current server status and information',
        mimeType: 'application/json'
    )]
    public function getServerStatus(): array
    {
        return [
            'status' => 'running',
            'timestamp' => time(),
            'version' => '1.0.0',
            'transport' => 'HTTP',
            'uptime' => time() - $_SERVER['REQUEST_TIME']
        ];
    }

    /**
     * Configuration resource
     */
    #[McpResource(
        uri: 'config://app/settings',
        name: 'app_config',
        description: 'Application configuration settings',
        mimeType: 'application/json'
    )]
    public function getAppConfig(): array
    {
        return [
            'debug' => $_SERVER['DEBUG'] ?? false,
            'environment' => $_SERVER['APP_ENV'] ?? 'production',
            'timezone' => date_default_timezone_get(),
            'locale' => 'en_US'
        ];
    }

    /**
     * Greeting prompt
     */
    #[McpPrompt(
        name: 'greet',
        description: 'Generate a personalized greeting message'
    )]
    public function greetPrompt(string $firstName = 'World', string $timeOfDay = 'day'): array
    {
        $greeting = match (strtolower($timeOfDay)) {
            'morning' => 'Good morning',
            'afternoon' => 'Good afternoon',
            'evening', 'night' => 'Good evening',
            default => 'Hello'
        };

        return [
            'role' => 'user',
            'content' => "# {$greeting}, {$firstName}!\n\nWelcome to our MCP HTTP Server example. This demonstrates how to use the Model Context Protocol over HTTP transport."
        ];
    }
}
