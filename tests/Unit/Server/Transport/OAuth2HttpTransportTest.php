<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Server\Auth\AuthenticationResult;
use Mcp\Server\Auth\OAuth2Configuration;
use Mcp\Server\Auth\ProtectedResourceMetadata;
use Mcp\Server\Auth\TokenAuthenticatorInterface;
use Mcp\Server\Transport\OAuth2HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \Mcp\Server\Transport\OAuth2HttpTransport
 */
final class OAuth2HttpTransportTest extends TestCase
{
    private Psr17Factory $factory;
    private ProtectedResourceMetadata $metadata;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['mcp:read', 'mcp:write'],
        );
    }

    public function testReturnsProtectedResourceMetadata(): void
    {
        $request = new ServerRequest('GET', '/.well-known/oauth-protected-resource');

        $transport = $this->createTransport($request);
        $response = $transport->listen();

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('https://mcp.example.com', $body['resource']);
        $this->assertSame(['https://auth.example.com'], $body['authorization_servers']);
    }

    public function testReturns401ForMissingToken(): void
    {
        $request = new ServerRequest('POST', '/mcp');

        $transport = $this->createTransport($request);
        $response = $transport->listen();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('WWW-Authenticate'));

        $wwwAuth = $response->getHeaderLine('WWW-Authenticate');
        $this->assertStringStartsWith('Bearer', $wwwAuth);
        $this->assertStringContainsString('resource_metadata', $wwwAuth);
    }

    public function testReturns401ForInvalidToken(): void
    {
        $mockAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $mockAuthenticator->method('authenticate')
            ->willReturn(AuthenticationResult::unauthenticated('invalid_token', 'Token expired'));

        $request = (new ServerRequest('POST', '/mcp'))
            ->withHeader('Authorization', 'Bearer invalid-token');

        $transport = $this->createTransport($request, $mockAuthenticator);
        $response = $transport->listen();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAllowsAuthenticatedRequest(): void
    {
        $mockAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $mockAuthenticator->method('authenticate')
            ->willReturn(AuthenticationResult::authenticated([
                'sub' => 'user-123',
                'scope' => 'mcp:read mcp:write',
            ]));

        // Create a valid JSON-RPC request
        $jsonBody = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Test', 'version' => '1.0'],
            ],
        ]);

        $request = (new ServerRequest('POST', '/mcp'))
            ->withHeader('Authorization', 'Bearer valid-token')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($jsonBody));

        $transport = $this->createTransport($request, $mockAuthenticator);
        $response = $transport->listen();

        // Should not be 401/403
        $this->assertNotEquals(401, $response->getStatusCode());
        $this->assertNotEquals(403, $response->getStatusCode());

        // Should have auth result
        $authResult = $transport->getAuthenticationResult();
        $this->assertNotNull($authResult);
        $this->assertTrue($authResult->authenticated);
        $this->assertSame('user-123', $authResult->getSubject());
    }

    public function testOptionsRequestBypassesAuth(): void
    {
        $request = new ServerRequest('OPTIONS', '/mcp');

        $transport = $this->createTransport($request);
        $response = $transport->listen();

        // OPTIONS should not require auth
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function testCreateForbiddenResponse(): void
    {
        $mockAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $mockAuthenticator->method('authenticate')
            ->willReturn(AuthenticationResult::authenticated(['sub' => 'user-123', 'scope' => 'read']));

        $request = (new ServerRequest('POST', '/mcp'))
            ->withHeader('Authorization', 'Bearer token');

        $config = new OAuth2Configuration(
            tokenAuthenticator: $mockAuthenticator,
            resourceMetadata: $this->metadata,
        );

        $transport = new OAuth2HttpTransport(
            $config,
            $request,
            $this->factory,
            $this->factory,
        );

        $response = $transport->createForbiddenResponse(['admin', 'write'], 'Admin access required');

        $this->assertSame(403, $response->getStatusCode());
        $wwwAuth = $response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('insufficient_scope', $wwwAuth);
        $this->assertStringContainsString('admin write', $wwwAuth);
    }

    public function testPublicPathBypassesAuth(): void
    {
        $mockAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);

        $config = new OAuth2Configuration(
            tokenAuthenticator: $mockAuthenticator,
            resourceMetadata: $this->metadata,
            publicPaths: ['/health', '/public/*'],
        );

        $request = new ServerRequest('GET', '/health');

        $transport = new OAuth2HttpTransport(
            $config,
            $request,
            $this->factory,
            $this->factory,
        );

        $response = $transport->listen();

        // Health check should not require auth
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    private function createTransport(
        ServerRequest $request,
        ?TokenAuthenticatorInterface $authenticator = null,
    ): OAuth2HttpTransport {
        if (null === $authenticator) {
            $authenticator = $this->createMock(TokenAuthenticatorInterface::class);
            $authenticator->method('authenticate')
                ->willReturn(AuthenticationResult::unauthenticated('invalid_token', 'No token'));
        }

        $config = new OAuth2Configuration(
            tokenAuthenticator: $authenticator,
            resourceMetadata: $this->metadata,
        );

        return new OAuth2HttpTransport(
            $config,
            $request,
            $this->factory,
            $this->factory,
        );
    }
}

