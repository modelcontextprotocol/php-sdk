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
use Mcp\Server\Transport\Middleware\OAuthProxyMiddleware;
use Mcp\Server\Transport\Middleware\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// Configuration from environment
$tenantId = getenv('AZURE_TENANT_ID') ?: throw new RuntimeException('AZURE_TENANT_ID environment variable is required');
$clientId = getenv('AZURE_CLIENT_ID') ?: throw new RuntimeException('AZURE_CLIENT_ID environment variable is required');

// Microsoft Entra ID issuer URLs
// v2.0 tokens (delegated/user flows): https://login.microsoftonline.com/{tenant}/v2.0
// v1.0 tokens (client credentials flow): https://sts.windows.net/{tenant}/
$issuerV2 = "https://login.microsoftonline.com/{$tenantId}/v2.0";
$issuerV1 = "https://sts.windows.net/{$tenantId}/";
$issuers = [$issuerV2, $issuerV1];

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

// Create JWT validator for Microsoft Entra ID
// Microsoft uses the client ID as the audience for access tokens
// Accept both v1.0 and v2.0 issuers to support various token flows
$validator = new JwtTokenValidator(
    issuer: $issuers,
    audience: $clientId,
    // Microsoft's JWKS endpoint - use common endpoint for all Microsoft signing keys
    jwksUri: 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
);

// Create Protected Resource Metadata (RFC 9728)
// Point to local authorization server (which proxies to Microsoft)
// This allows mcp-remote to use our /authorize and /token endpoints
$metadata = new ProtectedResourceMetadata(
    authorizationServers: ['http://localhost:8000'],
    scopesSupported: ['openid', 'profile', 'email'],
    resource: null,
);

// Get client secret for confidential client flow
$clientSecret = getenv('AZURE_CLIENT_SECRET') ?: null;

// Create OAuth proxy middleware to handle /authorize and /token endpoints
// This proxies OAuth requests to Microsoft Entra ID
// The clientSecret is injected server-side since mcp-remote doesn't have access to it
$oauthProxyMiddleware = new OAuthProxyMiddleware(
    upstreamIssuer: $issuerV2,
    localBaseUrl: 'http://localhost:8000',
    clientSecret: $clientSecret,
);

// Create authorization middleware
$authMiddleware = new AuthorizationMiddleware(
    metadata: $metadata,
    validator: $validator,
    metadataPaths: ['/.well-known/oauth-protected-resource'],
);

// Build MCP server
$server = Server::builder()
    ->setServerInfo('OAuth Microsoft Example', '1.0.0')
    ->setLogger($logger)
    ->setSession(new FileSessionStore('/tmp/mcp-sessions'))
    ->setDiscovery(__DIR__)
    ->build();

// Create transport with OAuth proxy and authorization middlewares
// Middlewares are reversed internally, so put OAuth proxy FIRST to execute FIRST
$transport = new StreamableHttpTransport(
    $request,
    logger: $logger,
    middlewares: [$oauthProxyMiddleware, $authMiddleware],
);

// Run server
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
