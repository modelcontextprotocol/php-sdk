<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mcp\Example\Server\OAuthKeycloak;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP elements for the OAuth Keycloak example.
 *
 * These tools demonstrate a protected MCP server.
 * All requests must include a valid OAuth bearer token.
 */
final class McpElements
{
    /**
     * Confirms the user is authenticated.
     *
     * The fact that this tool executes means the request passed OAuth validation.
     */
    #[McpTool(
        name: 'get_auth_status',
        description: 'Confirm authentication status - only accessible with valid OAuth token'
    )]
    public function getAuthStatus(): array
    {
        return [
            'authenticated' => true,
            'message' => 'You have successfully authenticated with OAuth!',
            'timestamp' => date('c'),
            'note' => 'This endpoint is protected by JWT validation. If you see this, your token was valid.',
        ];
    }

    /**
     * Simulates calling a protected external API.
     */
    #[McpTool(
        name: 'call_protected_api',
        description: 'Simulate calling a protected external API endpoint'
    )]
    public function callProtectedApi(
        string $endpoint,
        string $method = 'GET',
    ): array {
        // In a real implementation, you would:
        // 1. Use token exchange to get a token for the downstream API
        // 2. Or use client credentials with the user's context
        // 3. Make the actual HTTP call to the protected API

        return [
            'status' => 'success',
            'message' => sprintf('Simulated %s request to %s', $method, $endpoint),
            'simulated_response' => [
                'data' => 'This is simulated data from the protected API',
                'timestamp' => date('c'),
            ],
        ];
    }

    /**
     * Returns the current server time and status.
     */
    #[McpResource(
        uri: 'server://status',
        name: 'server_status',
        description: 'Current server status (protected resource)',
        mimeType: 'application/json'
    )]
    public function getServerStatus(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'php_version' => PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'protected' => true,
        ];
    }

    /**
     * A greeting prompt.
     */
    #[McpPrompt(
        name: 'greeting',
        description: 'Generate a greeting message'
    )]
    public function greeting(string $style = 'formal'): string
    {
        return match ($style) {
            'casual' => "Hey there! Welcome to the protected MCP server!",
            'formal' => "Good day. Welcome to the OAuth-protected MCP server.",
            'friendly' => "Hello! Great to have you here!",
            default => "Welcome to the MCP server!",
        };
    }
}
