<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\MicrosoftOAuth2;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;

/**
 * Example MCP elements for Microsoft OAuth2 authenticated server.
 */
final class McpElements
{
    /**
     * A simple protected tool that returns server info.
     *
     * @return TextContent[]
     */
    #[McpTool(
        name: 'get_server_info',
        description: 'Get information about the authenticated MCP server. Requires valid Microsoft Entra ID token.',
    )]
    public function getServerInfo(): array
    {
        return [
            new TextContent(json_encode([
                'name' => 'MCP Server with Microsoft Authentication',
                'version' => '1.0.0',
                'auth_provider' => 'Microsoft Entra ID',
                'timestamp' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Echo back a message - demonstrates a simple protected tool.
     *
     * @return TextContent[]
     */
    #[McpTool(
        name: 'echo',
        description: 'Echo back a message. Demonstrates a protected tool that requires authentication.',
    )]
    public function echo(string $message): array
    {
        return [
            new TextContent("Echo: {$message}"),
        ];
    }

    /**
     * Get a protected resource.
     *
     * @return TextContent[]
     */
    #[McpResource(
        uri: 'mcp://microsoft-auth/protected-data',
        name: 'Protected Data',
        description: 'A protected resource that requires authentication to access.',
    )]
    public function getProtectedData(): array
    {
        return [
            new TextContent(json_encode([
                'data' => 'This is protected data',
                'accessed_at' => date('c'),
                'message' => 'You successfully accessed protected data using Microsoft authentication!',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * A protected prompt.
     *
     * @return TextContent[]
     */
    #[McpPrompt(
        name: 'secure_assistant',
        description: 'A prompt for a secure assistant that requires authentication.',
    )]
    public function secureAssistantPrompt(?string $context = null): array
    {
        $promptText = "You are a secure assistant operating in an authenticated MCP server environment.\n\n";
        $promptText .= "The user has been authenticated via Microsoft Entra ID.\n";

        if ($context) {
            $promptText .= "\nAdditional context: {$context}";
        }

        return [
            new TextContent($promptText),
        ];
    }
}

