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

$tenantId = getenv('AZURE_TENANT_ID') ?: throw new RuntimeException('AZURE_TENANT_ID environment variable is required');
$clientId = getenv('AZURE_CLIENT_ID') ?: throw new RuntimeException('AZURE_CLIENT_ID environment variable is required');

$issuerV2 = "https://login.microsoftonline.com/{$tenantId}/v2.0";
$issuerV1 = "https://sts.windows.net/{$tenantId}/";
$localBaseUrl = 'http://localhost:8000';

$discovery = new OidcDiscovery(
    metadataPolicy: new MicrosoftOidcMetadataPolicy(),
);

$jwtTokenValidator = new JwtTokenValidator(
    issuer: [$issuerV2, $issuerV1],
    audience: $clientId,
    jwksProvider: new JwksProvider($discovery),
    jwksUri: 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
    scopeClaim: 'scp',
);

$validator = new MicrosoftJwtTokenValidator($jwtTokenValidator);

$protectedResourceMetadata = new ProtectedResourceMetadata(
    authorizationServers: [$localBaseUrl],
    scopesSupported: ['openid', 'profile', 'email'],
    resourceName: 'OAuth Microsoft Example MCP Server',
    resourceDocumentation: $localBaseUrl,
);

$metadataMiddleware = new ProtectedResourceMetadataMiddleware($protectedResourceMetadata);

$clientSecret = getenv('AZURE_CLIENT_SECRET') ?: null;

$oauthProxyMiddleware = new OAuthProxyMiddleware(
    upstreamIssuer: $issuerV2,
    localBaseUrl: $localBaseUrl,
    discovery: $discovery,
    clientSecret: $clientSecret,
);

$authMiddleware = new AuthorizationMiddleware(
    $validator,
    $protectedResourceMetadata,
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
    middleware: [$oauthProxyMiddleware, $metadataMiddleware, $authMiddleware, new OAuthRequestMetaMiddleware()],
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
