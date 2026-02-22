<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthMicrosoft;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * MCP elements for the OAuth Microsoft example.
 *
 * These tools demonstrate a protected MCP server using Microsoft Entra ID.
 * All requests must include a valid Microsoft-issued OAuth bearer token.
 */
final class McpElements
{
    /**
     * Confirms the user is authenticated with Microsoft.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_auth_status',
        description: 'Confirm Microsoft Entra ID authentication status'
    )]
    public function getAuthStatus(RequestContext $context): array
    {
        $meta = $context->getRequest()->getMeta() ?? [];
        $oauth = isset($meta['oauth']) && \is_array($meta['oauth']) ? $meta['oauth'] : [];
        $claims = isset($oauth['oauth.claims']) && \is_array($oauth['oauth.claims']) ? $oauth['oauth.claims'] : [];
        $scopes = isset($oauth['oauth.scopes']) && \is_array($oauth['oauth.scopes']) ? $oauth['oauth.scopes'] : [];

        return [
            'authenticated' => true,
            'provider' => 'Microsoft Entra ID',
            'message' => 'You have successfully authenticated with Microsoft!',
            'timestamp' => date('c'),
            'user' => [
                'subject' => $oauth['oauth.subject'] ?? ($claims['sub'] ?? null),
                'object_id' => $oauth['oauth.object_id'] ?? ($claims['oid'] ?? null),
                'username' => $claims['preferred_username'] ?? ($claims['upn'] ?? null),
                'name' => $oauth['oauth.name'] ?? ($claims['name'] ?? null),
                'email' => $claims['email'] ?? null,
                'issuer' => $claims['iss'] ?? null,
                'audience' => $claims['aud'] ?? null,
                'tenant_id' => $claims['tid'] ?? null,
                'scopes' => $scopes,
                'expires_at' => isset($claims['exp']) && is_numeric($claims['exp'])
                    ? date('c', (int) $claims['exp'])
                    : null,
            ],
        ];
    }

    /**
     * Simulates calling Microsoft Graph API.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'call_graph_api',
        description: 'Simulate calling Microsoft Graph API'
    )]
    public function callGraphApi(
        string $endpoint = '/me',
    ): array {
        // In a real implementation, you would:
        // 1. Use the On-Behalf-Of flow to exchange tokens
        // 2. Call Microsoft Graph with the new token

        return [
            'status' => 'simulated',
            'endpoint' => "https://graph.microsoft.com/v1.0{$endpoint}",
            'message' => 'Configure AZURE_CLIENT_SECRET for actual Graph API calls',
            'simulated_response' => [
                'displayName' => 'Demo User',
                'mail' => 'demo@example.com',
            ],
        ];
    }

    /**
     * Lists simulated emails.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_emails',
        description: 'List recent emails (simulated)'
    )]
    public function listEmails(int $count = 5): array
    {
        return [
            'note' => 'Simulated data. Implement Graph API call with Mail.Read scope for real emails.',
            'emails' => array_map(static fn ($i) => [
                'id' => 'msg_'.uniqid(),
                'subject' => "Sample Email #{$i}",
                'from' => "sender{$i}@example.com",
                'receivedDateTime' => date('c', strtotime("-{$i} hours")),
            ], range(1, $count)),
        ];
    }

    /**
     * Returns the current server status.
     *
     * @return array<string, mixed>
     */
    #[McpResource(
        uri: 'server://status',
        name: 'server_status',
        description: 'Current server status with Microsoft auth info',
        mimeType: 'application/json'
    )]
    public function getServerStatus(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'auth_provider' => 'Microsoft Entra ID',
            'php_version' => \PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * A Microsoft Teams-style message prompt.
     */
    #[McpPrompt(
        name: 'teams_message',
        description: 'Generate a Microsoft Teams-style message'
    )]
    public function teamsMessage(string $messageType = 'announcement'): string
    {
        return match ($messageType) {
            'announcement' => "📢 **Announcement**\n\nPlease add your announcement content here.",
            'question' => "❓ **Question**\n\nType your question here.",
            'update' => "📋 **Status Update**\n\n**Progress:**\n- Item 1\n- Item 2",
            default => "💬 **Message**\n\nYour message content here.",
        };
    }
}
