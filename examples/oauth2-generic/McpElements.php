<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\OAuth2Generic;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;

/**
 * Example MCP elements for generic OAuth2 authenticated server.
 */
final class McpElements
{
    /**
     * Get server status and authentication info.
     *
     * @return TextContent[]
     */
    #[McpTool(
        name: 'get_status',
        description: 'Get the current server status and authentication information.',
    )]
    public function getStatus(): array
    {
        return [
            new TextContent(json_encode([
                'status' => 'operational',
                'auth_type' => 'OAuth 2.0 Bearer Token',
                'server_time' => date('c'),
                'php_version' => PHP_VERSION,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Calculate the sum of two numbers.
     *
     * @return TextContent[]
     */
    #[McpTool(
        name: 'calculate',
        description: 'Perform a calculation. Demonstrates a protected tool.',
    )]
    public function calculate(float $a, float $b, string $operation = 'add'): array
    {
        $result = match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b !== 0.0 ? $a / $b : throw new \InvalidArgumentException('Cannot divide by zero'),
            default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
        };

        return [
            new TextContent(json_encode([
                'a' => $a,
                'b' => $b,
                'operation' => $operation,
                'result' => $result,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Get public configuration (no scope required).
     *
     * @return TextContent[]
     */
    #[McpResource(
        uri: 'mcp://oauth2/config',
        name: 'Server Configuration',
        description: 'Public server configuration.',
    )]
    public function getConfig(): array
    {
        return [
            new TextContent(json_encode([
                'name' => 'Generic OAuth2 MCP Server',
                'version' => '1.0.0',
                'supported_operations' => ['add', 'subtract', 'multiply', 'divide'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Get sensitive data (requires specific scope).
     *
     * @return TextContent[]
     */
    #[McpResource(
        uri: 'mcp://oauth2/sensitive-data',
        name: 'Sensitive Data',
        description: 'Sensitive data that requires elevated permissions.',
    )]
    public function getSensitiveData(): array
    {
        return [
            new TextContent(json_encode([
                'type' => 'sensitive',
                'data' => 'This data is only accessible with proper scopes',
                'accessed_at' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Default assistant prompt.
     *
     * @return TextContent[]
     */
    #[McpPrompt(
        name: 'oauth2_assistant',
        description: 'A prompt for an OAuth2-authenticated assistant.',
    )]
    public function assistantPrompt(?string $task = null): array
    {
        $prompt = "You are an assistant running in an OAuth 2.0 protected MCP server.\n";
        $prompt .= "The user has been authenticated and their access token has been validated.\n\n";

        if ($task) {
            $prompt .= "Your current task is: {$task}\n";
        }

        $prompt .= "\nAvailable tools:\n";
        $prompt .= "- get_status: Check server status\n";
        $prompt .= "- calculate: Perform mathematical calculations\n";

        return [
            new TextContent($prompt),
        ];
    }
}

