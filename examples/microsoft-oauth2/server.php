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

/**
 * MCP Server with Microsoft Entra ID (Azure AD) OAuth 2.0 Authentication.
 *
 * This example demonstrates how to protect an MCP server using Microsoft identity platform.
 *
 * Setup:
 * 1. Register an application in Azure Portal (App registrations)
 * 2. Configure API permissions and expose an API scope
 * 3. Set the following environment variables:
 *    - MICROSOFT_TENANT_ID: Your Azure AD tenant ID
 *    - MICROSOFT_CLIENT_ID: Your application (client) ID
 *    - MCP_SERVER_URL: Public URL of your MCP server (e.g., https://mcp.example.com)
 *
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Auth\JwtTokenAuthenticator;
use Mcp\Server\Auth\OAuth2Configuration;
use Mcp\Server\Auth\ProtectedResourceMetadata;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\OAuth2HttpTransport;

// Load configuration from environment
$tenantId = getenv('MICROSOFT_TENANT_ID') ?: throw new RuntimeException('MICROSOFT_TENANT_ID environment variable is required');
$clientId = getenv('MICROSOFT_CLIENT_ID') ?: throw new RuntimeException('MICROSOFT_CLIENT_ID environment variable is required');
$serverUrl = getenv('MCP_SERVER_URL') ?: 'http://localhost:8080';

logger()->info('Starting MCP Server with Microsoft Entra ID authentication...', [
    'tenant_id' => $tenantId,
    'client_id' => $clientId,
    'server_url' => $serverUrl,
]);

// Microsoft Entra ID JWKS endpoint
$jwksUri = "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys";
$issuer = "https://login.microsoftonline.com/{$tenantId}/v2.0";

// Create JWT authenticator for Microsoft tokens
$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: $jwksUri,
    issuer: $issuer,
    audience: $clientId, // Or use api://{clientId} for custom API scope
    algorithms: ['RS256'],
    leeway: 60,
    jwksCacheTtl: 3600,
    logger: logger(),
);

// Define protected resource metadata (RFC 9728)
// This tells MCP clients how to authenticate
$resourceMetadata = new ProtectedResourceMetadata(
    resource: $serverUrl,
    authorizationServers: [$issuer],
    scopesSupported: [
        'api://02d751ab-963d-4ea1-bfad-79c2ed220269/mcp.read',
        'api://02d751ab-963d-4ea1-bfad-79c2ed220269/mcp.write',
        'api://02d751ab-963d-4ea1-bfad-79c2ed220269/mcp.admin',
        'http://localhost:8080/mcp.read',
        'http://localhost:8080/mcp.write',
        'http://localhost:8080/mcp.admin',
    ],
    bearerMethodsSupported: ['header'],
    resourceName: 'MCP Server with Microsoft Auth 3',
    resourceDocumentation: 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
);

// OAuth2 configuration
$authConfig = new OAuth2Configuration(
    tokenAuthenticator: $tokenAuthenticator,
    resourceMetadata: $resourceMetadata,
    publicPaths: [
        '/health', // Health check endpoint
    ],
);

// Build the server
$server = Server::builder()
    ->setServerInfo('MicrosoftAuthMcpServer', '1.0.0', 'MCP Server with Microsoft Entra ID Authentication')
    ->setInstructions('This MCP server requires Microsoft Entra ID authentication. Obtain an access token from Azure AD and include it in the Authorization header.')
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger(logger())
    ->setDiscovery(__DIR__)
    ->build();

// Create OAuth2-enabled transport
if ('cli' === PHP_SAPI) {
    // For CLI testing, skip OAuth2 (STDIO doesn't support it per MCP spec)
    $transport = new \Mcp\Server\Transport\StdioTransport(logger: logger());
    logger()->info('Running in STDIO mode (no OAuth2)');
} else {
    $request = (new Psr17Factory())->createServerRequestFromGlobals();
    $transport = new OAuth2HttpTransport(
        authConfig: $authConfig,
        request: $request,
        logger: logger(),
    );
    logger()->info('Running in HTTP mode with OAuth2 authentication');
}

$result = $server->run($transport);

logger()->info('Server stopped.', ['result' => is_int($result) ? $result : 'response']);

shutdown($result);
