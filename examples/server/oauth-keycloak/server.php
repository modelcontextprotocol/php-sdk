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

use Firebase\JWT\CachedKeySet;
use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr18ClientDiscovery;
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

// Tokens are issued to clients through the published port, so they carry the external issuer.
$issuer = 'http://localhost:8180/realms/mcp';
$resource = 'http://localhost:8000/mcp';

// Inside the Docker network Keycloak is only reachable over plain http, which
// JwtTokenValidator::fromIssuer() refuses; the key set is built explicitly instead.
$keys = new CachedKeySet(
    'http://keycloak:8180/realms/mcp/protocol/openid-connect/certs',
    Psr18ClientDiscovery::find(),
    new Psr17Factory(),
    new FilesystemAdapter('mcp-keycloak', 3600, __DIR__.'/cache'),
    3600,
    true,
);

$validator = new JwtTokenValidator(
    issuer: $issuer,
    audience: $resource,
    keys: $keys,
);

$protectedResourceMetadata = new ProtectedResourceMetadata(
    resource: $resource,
    authorizationServers: [$issuer],
    scopesSupported: ['mcp:read'],
    resourceName: 'OAuth Keycloak Example MCP Server',
);

$scopePolicy = new ScopePolicy(
    default: ['mcp:read'],
    tools: ['call_protected_api' => ['mcp:write']],
);

$server = Server::builder()
    ->setServerInfo('OAuth Keycloak Example', '1.0.0')
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
        new AuthorizationMiddleware($validator, $protectedResourceMetadata, $scopePolicy),
    ],
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
