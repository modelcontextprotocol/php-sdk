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
use Mcp\Example\Server\OAuthAuthorizationServer\DemoResourceOwnerResolver;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationEndpointMiddleware;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\AuthorizationServerMetadataMiddleware;
use Mcp\Server\Transport\Http\Middleware\ClientRegistrationMiddleware;
use Mcp\Server\Transport\Http\Middleware\JwksMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\Middleware\TokenEndpointMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationServerMetadata;
use Mcp\Server\Transport\Http\OAuth\AutoApproveConsent;
use Mcp\Server\Transport\Http\OAuth\CacheAuthorizationCodeStore;
use Mcp\Server\Transport\Http\OAuth\CacheClientRepository;
use Mcp\Server\Transport\Http\OAuth\CacheRefreshTokenStore;
use Mcp\Server\Transport\Http\OAuth\JwtAccessTokenIssuer;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\NativeAuthorizationCodeIssuer;
use Mcp\Server\Transport\Http\OAuth\NativeTokenGranter;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\RsaSigningKey;
use Mcp\Server\Transport\Http\OAuth\StaticJwksProvider;
use Mcp\Server\Transport\Http\OAuth\StoredClientRegistrar;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$baseUrl = getenv('MCP_BASE_URL') ?: 'http://localhost:8000';
$scopes = ['mcp:tools', 'mcp:resources'];

// --- Signing key (generated on first run; replace with a managed key in production) ---
$keyPath = __DIR__.'/keys/private.pem';
if (!is_file($keyPath)) {
    @mkdir(dirname($keyPath), 0700, true);
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $pem);
    file_put_contents($keyPath, $pem);
}
$signingKey = RsaSigningKey::fromFile($keyPath);

// --- Storage (PSR-16 filesystem cache so state survives across requests under
//     `php -S`; swap for your own ClientRepository/stores in production) ---
$cache = new Psr16Cache(new FilesystemAdapter('mcp_oauth', 0, __DIR__.'/cache'));
$clients = new CacheClientRepository($cache);
$codes = new CacheAuthorizationCodeStore($cache);
$refreshTokens = new CacheRefreshTokenStore($cache);

// --- Authorization server engine ---
$accessTokenIssuer = new JwtAccessTokenIssuer($signingKey, $baseUrl);
$codeIssuer = new NativeAuthorizationCodeIssuer($codes);
$granter = new NativeTokenGranter($clients, $codes, $refreshTokens, $accessTokenIssuer, resource: $baseUrl);

// --- Resource server: validate our own self-issued tokens, no network needed ---
$tokenValidator = new JwtTokenValidator(
    issuer: $baseUrl,
    audience: $baseUrl,
    jwksProvider: new StaticJwksProvider($signingKey),
);

$authServerMetadata = new AuthorizationServerMetadata(
    issuer: $baseUrl,
    scopesSupported: $scopes,
);

$protectedResourceMetadata = new ProtectedResourceMetadata(
    authorizationServers: [$baseUrl],
    scopesSupported: $scopes,
    resource: $baseUrl,
    resourceName: 'OAuth Authorization Server Example',
);

$server = Server::builder()
    ->setServerInfo('OAuth Authorization Server Example', '1.0.0')
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    logger: logger(),
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        // ClientRegistration must be OUTER of the metadata middleware so it can
        // enrich the served document with registration_endpoint.
        new ClientRegistrationMiddleware(new StoredClientRegistrar($clients, $scopes), $baseUrl),
        new AuthorizationServerMetadataMiddleware($authServerMetadata),
        new JwksMiddleware($signingKey),
        new ProtectedResourceMetadataMiddleware($protectedResourceMetadata),
        new AuthorizationEndpointMiddleware($clients, $codeIssuer, new DemoResourceOwnerResolver(), new AutoApproveConsent(), $scopes),
        new TokenEndpointMiddleware($granter),
        new AuthorizationMiddleware($tokenValidator, $protectedResourceMetadata),
        new OAuthRequestMetaMiddleware(),
    ],
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
