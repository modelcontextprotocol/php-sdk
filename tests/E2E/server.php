<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The protected MCP server the end-to-end test authorizes against.
 *
 * Nothing here is test scaffolding: it is the SDK's own authorization middleware in
 * front of the SDK's own HTTP transport, validating real Keycloak-issued JWTs against
 * real published keys. The client under test has to find its way in from a bare 401.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;

$externalIssuer = getenv('MCP_E2E_ISSUER_EXTERNAL') ?: 'http://localhost:8181/realms/mcp';
$internalIssuer = getenv('MCP_E2E_ISSUER_INTERNAL') ?: 'http://keycloak:8181/realms/mcp';
$resource = getenv('MCP_E2E_RESOURCE') ?: 'http://localhost:8001/mcp';

$metadata = new ProtectedResourceMetadata(
    authorizationServers: [$externalIssuer],
    // Only what a client may name in an authorization request. Keycloak assigns this
    // client its "mcp" scope by default -- and rejects a request that asks for a
    // default scope explicitly -- so advertising it here would send clients into a
    // rejection they cannot do anything about.
    scopesSupported: ['openid'],
    resource: $resource,
    resourceName: 'Client authorization end-to-end server',
);

$server = Server::builder()
    ->setServerInfo('client-auth-e2e-server', '1.0.0')
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->addTool(
        static fn (): string => 'authorized',
        name: 'whoami',
        description: 'Answers only when the caller presented a token this server trusts.',
    )
    ->build();

$response = $server->run(new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        new ProtectedResourceMetadataMiddleware($metadata),
        new AuthorizationMiddleware(
            new JwtTokenValidator(
                issuer: [$externalIssuer, $internalIssuer],
                audience: 'mcp-server',
                jwksProvider: new JwksProvider(new OidcDiscovery()),
                jwksUri: $internalIssuer.'/protocol/openid-connect/certs',
            ),
            $metadata,
        ),
    ],
));

(new SapiEmitter())->emit($response);
