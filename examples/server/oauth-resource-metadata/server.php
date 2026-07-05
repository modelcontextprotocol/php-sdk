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
chdir(__DIR__);

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Mcp\Server\Transport\StreamableHttpTransport;

/*
 * This example demonstrates "bridging both worlds": the same RFC 9728 metadata
 * component is served WITHOUT the PSR-15 middleware chain, as a plain request
 * handler — exactly the shape a Symfony/Laravel callable controller expects.
 *
 * This tiny front controller plays the role of the framework router: it decides
 * *when* the metadata endpoint applies and lets ProtectedResourceMetadataHandler
 * decide *what* to return. The /mcp JSON-RPC endpoint keeps using the transport.
 */

$request = (new Psr17Factory())->createServerRequestFromGlobals();

$metadata = new ProtectedResourceMetadata(
    authorizationServers: ['https://auth.example.com'],
    scopesSupported: ['mcp:read', 'mcp:write'],
    resource: 'http://localhost:8000/mcp',
    resourceName: 'OAuth Resource Metadata Example',
    resourceDocumentation: 'https://modelcontextprotocol.io',
);

// The reusable handler — a plain PSR-15 RequestHandlerInterface, no transport,
// no middleware chain. Mount it on a framework route to serve the endpoint there.
$metadataHandler = new ProtectedResourceMetadataHandler($metadata);

$path = $request->getUri()->getPath();

if ('GET' === $request->getMethod() && ProtectedResourceMetadata::DEFAULT_METADATA_PATH === $path) {
    // The "callable controller" path: hand the request straight to the handler.
    (new SapiEmitter())->emit($metadataHandler->handle($request));
    exit(0);
}

// Everything else is normal MCP traffic over the streamable HTTP transport.
$server = Server::builder()
    ->setServerInfo('OAuth Resource Metadata Example', '1.0.0')
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

$transport = new StreamableHttpTransport($request, logger: logger());

(new SapiEmitter())->emit($server->run($transport));
