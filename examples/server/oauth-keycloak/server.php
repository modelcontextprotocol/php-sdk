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
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;

$externalIssuer = 'http://localhost:8180/realms/mcp';
$internalIssuer = 'http://keycloak:8180/realms/mcp';

$validator = new JwtTokenValidator(
    issuer: [$externalIssuer, $internalIssuer],
    audience: 'mcp-server',
    jwksProvider: new JwksProvider(new OidcDiscovery()),
    jwksUri: $internalIssuer.'/protocol/openid-connect/certs',
);

$protectedResourceMetadata = new ProtectedResourceMetadata(
    authorizationServers: [$externalIssuer],
    scopesSupported: ['openid'],
    resource: 'http://localhost:8000/mcp',
    resourceName: 'OAuth Keycloak Example MCP Server',
);

$metadataMiddleware = new ProtectedResourceMetadataMiddleware($protectedResourceMetadata);

$authMiddleware = new AuthorizationMiddleware(
    $validator,
    $protectedResourceMetadata,
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
    middleware: [$metadataMiddleware, $authMiddleware, new OAuthRequestMetaMiddleware()],
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
