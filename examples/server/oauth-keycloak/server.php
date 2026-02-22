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

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;

// Configuration
// External URL is what clients use and what appears in tokens
$keycloakExternalUrl = 'http://localhost:8180';
// Internal URL is how this server reaches Keycloak (Docker network)
$keycloakInternalUrl = 'http://keycloak:8080';
$keycloakRealm = 'mcp';
$mcpAudience = 'mcp-server';

// Accept both issuers:
// - external issuer for clients outside Docker
// - internal issuer for tokens requested from within Docker network
$externalIssuer = rtrim($keycloakExternalUrl, '/').'/realms/'.$keycloakRealm;
$internalIssuer = rtrim($keycloakInternalUrl, '/').'/realms/'.$keycloakRealm;
// JWKS URI uses internal URL to reach Keycloak within Docker network
$jwksUri = rtrim($keycloakInternalUrl, '/').'/realms/'.$keycloakRealm.'/protocol/openid-connect/certs';

// Create PSR-17 factory
$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

// Create JWT validator
// - issuer: accepts both external and internal issuer forms
// - jwksUri: where to fetch keys (internal URL)
$validator = new JwtTokenValidator(
    issuer: [$externalIssuer, $internalIssuer],
    audience: $mcpAudience,
    jwksProvider: new JwksProvider(),
    jwksUri: $jwksUri,
);

// Create a shared Protected Resource Metadata object (RFC 9728).
// It is used both for the metadata endpoint and for WWW-Authenticate hints.
$protectedResourceMetadata = new ProtectedResourceMetadata(
    authorizationServers: [$externalIssuer],
    scopesSupported: ['openid'],
    resource: 'http://localhost:8000/mcp',
    resourceName: 'OAuth Keycloak Example MCP Server',
);

// Create middleware serving Protected Resource Metadata (RFC 9728).
$metadataMiddleware = new ProtectedResourceMetadataMiddleware(
    metadata: $protectedResourceMetadata,
);

// Create authorization middleware.
$authMiddleware = new AuthorizationMiddleware(
    validator: $validator,
    resourceMetadata: $protectedResourceMetadata,
);
$oauthRequestMetaMiddleware = new OAuthRequestMetaMiddleware();

// Build MCP server
$server = Server::builder()
    ->setServerInfo('OAuth Keycloak Example', '1.0.0')
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

// Create transport with authorization middleware
$transport = new StreamableHttpTransport(
    $request,
    logger: logger(),
    middleware: [$metadataMiddleware, $authMiddleware, $oauthRequestMetaMiddleware],
);

// Run server
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
