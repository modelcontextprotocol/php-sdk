<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\Middleware;

use Mcp\Server\Transport\Http\Middleware\OAuthProxyMiddleware;
use Mcp\Server\Transport\Http\OAuth\OidcDiscoveryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests OAuthProxyMiddleware behavior for metadata, authorize, and token routes.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class OAuthProxyMiddlewareTest extends TestCase
{
    #[TestDox('metadata endpoint returns local oauth metadata with upstream capabilities')]
    public function testMetadataEndpointReturnsLocalMetadata(): void
    {
        $factory = new Psr17Factory();
        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->once())
            ->method('discover')
            ->with('https://login.example.com/tenant')
            ->willReturn([
                'authorization_endpoint' => 'https://login.example.com/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.example.com/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.example.com/discovery/v2.0/keys',
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'scopes_supported' => ['openid', 'profile'],
                'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            ]);

        $middleware = new OAuthProxyMiddleware(
            upstreamIssuer: 'https://login.example.com/tenant',
            localBaseUrl: 'http://localhost:8000',
            discovery: $discovery,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(404);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('http://localhost:8000', $payload['issuer']);
        $this->assertSame('http://localhost:8000/authorize', $payload['authorization_endpoint']);
        $this->assertSame('http://localhost:8000/token', $payload['token_endpoint']);
        $this->assertSame(['openid', 'profile'], $payload['scopes_supported']);
        $this->assertSame('https://login.example.com/discovery/v2.0/keys', $payload['jwks_uri']);
    }

    #[TestDox('authorize endpoint redirects to upstream authorization endpoint preserving query')]
    public function testAuthorizeEndpointRedirectsToUpstream(): void
    {
        $factory = new Psr17Factory();
        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->once())
            ->method('getAuthorizationEndpoint')
            ->with('https://login.example.com/tenant')
            ->willReturn('https://login.example.com/oauth2/v2.0/authorize');

        $middleware = new OAuthProxyMiddleware(
            upstreamIssuer: 'https://login.example.com/tenant',
            localBaseUrl: 'http://localhost:8000',
            discovery: $discovery,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest(
            'GET',
            'http://localhost:8000/authorize?client_id=test-client&scope=openid%20profile&code_challenge=abc',
        );

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(404);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'https://login.example.com/oauth2/v2.0/authorize?client_id=test-client&scope=openid%20profile&code_challenge=abc',
            $response->getHeaderLine('Location'),
        );
    }

    #[TestDox('token endpoint proxies request and injects client secret')]
    public function testTokenEndpointProxiesRequestAndInjectsClientSecret(): void
    {
        $factory = new Psr17Factory();
        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->once())
            ->method('getTokenEndpoint')
            ->with('https://login.example.com/tenant')
            ->willReturn('https://login.example.com/oauth2/v2.0/token');
        $discovery->expects($this->once())
            ->method('discover')
            ->with('https://login.example.com/tenant')
            ->willReturn([
                'authorization_endpoint' => 'https://login.example.com/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.example.com/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.example.com/discovery/v2.0/keys',
                'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            ]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($factory): ResponseInterface {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('https://login.example.com/oauth2/v2.0/token', (string) $request->getUri());
                $this->assertSame('', $request->getHeaderLine('Authorization'));
                $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

                parse_str($request->getBody()->__toString(), $params);
                $this->assertSame('authorization_code', $params['grant_type'] ?? null);
                $this->assertSame('abc123', $params['code'] ?? null);
                $this->assertSame('secret-value', $params['client_secret'] ?? null);

                return $factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream('{"access_token":"token-1"}'));
            });

        $middleware = new OAuthProxyMiddleware(
            upstreamIssuer: 'https://login.example.com/tenant',
            localBaseUrl: 'http://localhost:8000',
            clientSecret: 'secret-value',
            discovery: $discovery,
            httpClient: $httpClient,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest('POST', 'http://localhost:8000/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('grant_type=authorization_code&code=abc123'));

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(404);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"access_token":"token-1"}', $response->getBody()->__toString());
    }

    #[TestDox('token endpoint uses client_secret_basic when supported by upstream metadata')]
    public function testTokenEndpointUsesClientSecretBasicWhenSupported(): void
    {
        $factory = new Psr17Factory();
        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->once())
            ->method('getTokenEndpoint')
            ->with('https://login.example.com/tenant')
            ->willReturn('https://login.example.com/oauth2/v2.0/token');
        $discovery->expects($this->once())
            ->method('discover')
            ->with('https://login.example.com/tenant')
            ->willReturn([
                'authorization_endpoint' => 'https://login.example.com/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.example.com/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.example.com/discovery/v2.0/keys',
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            ]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($factory): ResponseInterface {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('https://login.example.com/oauth2/v2.0/token', (string) $request->getUri());
                $this->assertSame('Basic ZGVtby1jbGllbnQ6c2VjcmV0LXZhbHVl', $request->getHeaderLine('Authorization'));
                $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

                parse_str($request->getBody()->__toString(), $params);
                $this->assertSame('authorization_code', $params['grant_type'] ?? null);
                $this->assertSame('abc123', $params['code'] ?? null);
                $this->assertArrayNotHasKey('client_secret', $params);

                return $factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream('{"access_token":"token-1"}'));
            });

        $middleware = new OAuthProxyMiddleware(
            upstreamIssuer: 'https://login.example.com/tenant',
            localBaseUrl: 'http://localhost:8000',
            clientSecret: 'secret-value',
            discovery: $discovery,
            httpClient: $httpClient,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest('POST', 'http://localhost:8000/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('grant_type=authorization_code&client_id=demo-client&code=abc123'));

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(404);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"access_token":"token-1"}', $response->getBody()->__toString());
    }

    #[TestDox('non oauth proxy requests are delegated to next middleware')]
    public function testNonOAuthRequestPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->never())->method('discover');

        $middleware = new OAuthProxyMiddleware(
            upstreamIssuer: 'https://login.example.com/tenant',
            localBaseUrl: 'http://localhost:8000',
            discovery: $discovery,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'http://localhost:8000/mcp');
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(204);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(204, $response->getStatusCode());
    }
}
