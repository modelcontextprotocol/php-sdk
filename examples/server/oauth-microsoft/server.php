<?php

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
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$tenantId = getenv('AZURE_TENANT_ID') ?: throw new RuntimeException('AZURE_TENANT_ID environment variable is required');
$clientId = getenv('AZURE_CLIENT_ID') ?: throw new RuntimeException('AZURE_CLIENT_ID environment variable is required');

// v2.0 access tokens; set "accessTokenAcceptedVersion": 2 in the app manifest.
$issuer = "https://login.microsoftonline.com/{$tenantId}/v2.0";
$scope = "api://{$clientId}/mcp.access";

$validator = JwtTokenValidator::fromIssuer(
    issuer: $issuer,
    // v2.0 tokens name the API by its client id, v1.0 tokens by its Application ID URI.
    audience: [$clientId, "api://{$clientId}"],
    cache: new FilesystemAdapter('mcp-entra', 3600, __DIR__.'/cache'),
    scopeClaim: 'scp',
);

$protectedResourceMetadata = new ProtectedResourceMetadata(
    resource: 'http://localhost:8000/mcp',
    authorizationServers: [$issuer],
    scopesSupported: [$scope],
    resourceName: 'OAuth Microsoft Example MCP Server',
);

$server = Server::builder()
    ->setServerInfo('OAuth Microsoft Example', '1.0.0')
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    logger: logger(),
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        new ProtectedResourceMetadataMiddleware($protectedResourceMetadata),
        // Entra puts delegated scopes into "scp" without the api:// prefix, so the 403
        // challenge names the bare scope as well; see "403 insufficient_scope" in the README.
        new AuthorizationMiddleware($validator, $protectedResourceMetadata, new ScopePolicy(default: ['mcp.access'])),
    ],
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
