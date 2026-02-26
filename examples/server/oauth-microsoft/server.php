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

require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/MicrosoftJwtTokenValidator.php';
require_once __DIR__.'/MicrosoftOidcMetadataPolicy.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Example\Server\OAuthMicrosoft\MicrosoftJwtTokenValidator;
use Mcp\Example\Server\OAuthMicrosoft\MicrosoftOidcMetadataPolicy;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthProxyMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;

// Configuration from environment
$tenantId = getenv('AZURE_TENANT_ID') ?: throw new RuntimeException('AZURE_TENANT_ID environment variable is required');
$clientId = getenv('AZURE_CLIENT_ID') ?: throw new RuntimeException('AZURE_CLIENT_ID environment variable is required');

// Microsoft Entra ID issuer URLs
// v2.0 tokens (delegated/user flows): https://login.microsoftonline.com/{tenant}/v2.0
// v1.0 tokens (client credentials flow): https://sts.windows.net/{tenant}/
$issuerV2 = "https://login.microsoftonline.com/{$tenantId}/v2.0";
$issuerV1 = "https://sts.windows.net/{$tenantId}/";
$issuers = [$issuerV2, $issuerV1];
$localBaseUrl = 'http://localhost:8000';

// Create PSR-17 factory
$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();
$discovery = new OidcDiscovery(
    metadataPolicy: new MicrosoftOidcMetadataPolicy(),
);

// Create base JWT validator for Microsoft Entra ID
// Microsoft uses the client ID as the audience for access tokens
// Accept both v1.0 and v2.0 issuers to support various token flows
$jwtTokenValidator = new JwtTokenValidator(
    issuer: $issuers,
    audience: $clientId,
    jwksProvider: new JwksProvider(discovery: $discovery),
    // Microsoft's JWKS endpoint - use common endpoint for all Microsoft signing keys
    jwksUri: 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
    scopeClaim: 'scp',
);

// Decorate base validator with Graph-token handling.
$validator = new MicrosoftJwtTokenValidator(
    jwtTokenValidator: $jwtTokenValidator,
);

// Create a shared Protected Resource Metadata object (RFC 9728).
// It is used both for the metadata endpoint and for WWW-Authenticate hints.
$protectedResourceMetadata = new ProtectedResourceMetadata(
    authorizationServers: [$localBaseUrl],
    scopesSupported: ['openid', 'profile', 'email'],
    resourceName: 'OAuth Microsoft Example MCP Server',
    resourceDocumentation: $localBaseUrl,
);

// Create middleware serving Protected Resource Metadata (RFC 9728).
$metadataMiddleware = new ProtectedResourceMetadataMiddleware(
    metadata: $protectedResourceMetadata,
);

// Get client secret for confidential client flow
$clientSecret = getenv('AZURE_CLIENT_SECRET') ?: null;

// Create OAuth proxy middleware to handle /authorize and /token endpoints
// This proxies OAuth requests to Microsoft Entra ID
// The clientSecret is injected server-side since mcp-remote doesn't have access to it
$oauthProxyMiddleware = new OAuthProxyMiddleware(
    upstreamIssuer: $issuerV2,
    localBaseUrl: $localBaseUrl,
    discovery: $discovery,
    clientSecret: $clientSecret,
);

// Create authorization middleware
$authMiddleware = new AuthorizationMiddleware(
    validator: $validator,
    resourceMetadata: $protectedResourceMetadata,
);
$oauthRequestMetaMiddleware = new OAuthRequestMetaMiddleware();

// Build MCP server
$server = Server::builder()
    ->setServerInfo('OAuth Microsoft Example', '1.0.0')
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

// Create transport with OAuth proxy and authorization middlewares
// Order matters: first matching middleware handles the request.
$transport = new StreamableHttpTransport(
    $request,
    logger: logger(),
    middleware: [$oauthProxyMiddleware, $metadataMiddleware, $authMiddleware, $oauthRequestMetaMiddleware],
);

// Run server
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
