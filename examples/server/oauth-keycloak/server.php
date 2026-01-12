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

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Middleware\JwtTokenValidator;
use Mcp\Server\Transport\Middleware\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Log\AbstractLogger;

// Configuration from environment
// External URL is what clients use and what appears in tokens
$keycloakExternalUrl = getenv('KEYCLOAK_EXTERNAL_URL') ?: 'http://localhost:8180';
// Internal URL is how this server reaches Keycloak (Docker network)
$keycloakInternalUrl = getenv('KEYCLOAK_INTERNAL_URL') ?: 'http://keycloak:8080';
$keycloakRealm = getenv('KEYCLOAK_REALM') ?: 'mcp';
$mcpAudience = getenv('MCP_AUDIENCE') ?: 'mcp-server';

// Issuer is what appears in the token (external URL)
$issuer = rtrim($keycloakExternalUrl, '/').'/realms/'.$keycloakRealm;
// JWKS URI uses internal URL to reach Keycloak within Docker network
$jwksUri = rtrim($keycloakInternalUrl, '/').'/realms/'.$keycloakRealm.'/protocol/openid-connect/certs';

// Create logger
$logger = new class extends AbstractLogger {
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $logMessage = sprintf("[%s] %s\n", strtoupper($level), $message);
        error_log($logMessage);
    }
};

// Create PSR-17 factory
$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

// Create JWT validator
// - issuer: matches what's in the token (external URL)
// - jwksUri: where to fetch keys (internal URL)
$validator = new JwtTokenValidator(
    issuer: $issuer,
    audience: $mcpAudience,
    jwksUri: $jwksUri,
);

// Create Protected Resource Metadata (RFC 9728)
// Authorization server URL should be the external URL for clients
// scopesSupported must match what Keycloak's mcp-client allows
$metadata = new ProtectedResourceMetadata(
    authorizationServers: [$issuer],
    scopesSupported: ['openid'],
    resource: 'http://localhost:8000/mcp',
);

// Create authorization middleware
$authMiddleware = new AuthorizationMiddleware(
    metadata: $metadata,
    validator: $validator,
    metadataPaths: ['/.well-known/oauth-protected-resource'],
);

// Build MCP server
$server = Server::builder()
    ->setServerInfo('OAuth Keycloak Example', '1.0.0')
    ->setLogger($logger)
    ->setSession(new FileSessionStore('/tmp/mcp-sessions'))
    ->setDiscovery(__DIR__)
    ->build();

// Create transport with authorization middleware
$transport = new StreamableHttpTransport(
    $request,
    logger: $logger,
    middlewares: [$authMiddleware],
);

// Run server
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
