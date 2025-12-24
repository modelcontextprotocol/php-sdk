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
 * MCP Server with Generic OAuth 2.0 Authentication.
 *
 * This example demonstrates how to protect an MCP server using any OAuth 2.0
 * authorization server that supports JWKS for token validation.
 *
 * Works with:
 * - Auth0
 * - Okta
 * - Keycloak
 * - Any OIDC-compliant provider
 *
 * Setup:
 * 1. Configure your OAuth provider and obtain:
 *    - OAUTH_JWKS_URI: JWKS endpoint URL
 *    - OAUTH_ISSUER: Token issuer URL
 *    - OAUTH_AUDIENCE: Expected audience (your API identifier)
 * 2. Set the MCP_SERVER_URL to your server's public URL
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
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
$jwksUri = getenv('OAUTH_JWKS_URI') ?: throw new RuntimeException('OAUTH_JWKS_URI environment variable is required');
$issuer = getenv('OAUTH_ISSUER') ?: throw new RuntimeException('OAUTH_ISSUER environment variable is required');
$audience = getenv('OAUTH_AUDIENCE') ?: null;
$serverUrl = getenv('MCP_SERVER_URL') ?: 'http://localhost:8080';
$scopesSupported = getenv('OAUTH_SCOPES') ? explode(' ', getenv('OAUTH_SCOPES')) : ['read', 'write'];
$validateAudience = filter_var(getenv('OAUTH_VALIDATE_AUDIENCE') ?: 'false', FILTER_VALIDATE_BOOLEAN);

logger()->info('Starting MCP Server with OAuth 2.0 authentication...', [
    'jwks_uri' => $jwksUri,
    'issuer' => $issuer,
    'audience' => $audience,
    'server_url' => $serverUrl,
]);

// Create JWT authenticator
$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: $jwksUri,
    issuer: $issuer,
    audience: $audience,
    algorithms: ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'],
    leeway: 60,
    jwksCacheTtl: 3600,
    logger: logger(),
);

// Define protected resource metadata (RFC 9728)
$resourceMetadata = new ProtectedResourceMetadata(
    resource: $serverUrl,
    authorizationServers: [$issuer],
    scopesSupported: $scopesSupported,
    bearerMethodsSupported: ['header'],
    resourceName: 'Generic OAuth2 MCP Server',
);

// OAuth2 configuration
$authConfig = new OAuth2Configuration(
    tokenAuthenticator: $tokenAuthenticator,
    resourceMetadata: $resourceMetadata,
    validateAudience: $validateAudience,
);

// Build the server
$server = Server::builder()
    ->setServerInfo('GenericOAuth2McpServer', '1.0.0', 'MCP Server with Generic OAuth 2.0 Authentication')
    ->setInstructions('This MCP server requires OAuth 2.0 authentication. Obtain an access token from the configured authorization server and include it in the Authorization header.')
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger(logger())
    ->setDiscovery(__DIR__)
    ->build();

// Create transport based on SAPI
if ('cli' === PHP_SAPI) {
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

