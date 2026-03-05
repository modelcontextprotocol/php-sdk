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

use Mcp\Exception\RuntimeException;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests AuthorizationMiddleware behavior for token validation and challenges.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class AuthorizationMiddlewareTest extends TestCase
{
    #[TestDox('missing Authorization header returns 401 with metadata and scope guidance')]
    public function testMissingAuthorizationReturns401(): void
    {
        $factory = new Psr17Factory();
        $resourceMetadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['mcp:read'],
        );
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                throw new RuntimeException('Validator should not be called without a token.');
            }
        };

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            resourceMetadata: $resourceMetadata,
            responseFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
        $header = $response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $header);
        $this->assertStringContainsString(
            'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"',
            $header,
        );
        $this->assertStringContainsString('scope="mcp:read"', $header);
    }

    #[TestDox('malformed Authorization header returns 400 with invalid_request')]
    public function testMalformedAuthorizationReturns400(): void
    {
        $factory = new Psr17Factory();
        $resourceMetadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow();
            }
        };

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            resourceMetadata: $resourceMetadata,
            responseFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp')
            ->withHeader('Authorization', 'Basic abc');

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[TestDox('insufficient scopes return 403 with scope challenge')]
    public function testInsufficientScopeReturns403(): void
    {
        $factory = new Psr17Factory();
        $resourceMetadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::forbidden('insufficient_scope', 'Need more scopes.', ['mcp:write']);
            }
        };

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            resourceMetadata: $resourceMetadata,
            responseFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp')
            ->withHeader('Authorization', 'Bearer token');

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $header = $response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('error="insufficient_scope"', $header);
        $this->assertStringContainsString('scope="mcp:write"', $header);
    }

    #[TestDox('metadata scopes are used in challenge when result has no scopes')]
    public function testMetadataScopesAreUsedWhenResultHasNoScopes(): void
    {
        $factory = new Psr17Factory();
        $resourceMetadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['openid', 'profile'],
        );
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                throw new RuntimeException('Validator should not be called without a token.');
            }
        };

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            resourceMetadata: $resourceMetadata,
            responseFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);
        $header = $response->getHeaderLine('WWW-Authenticate');

        $this->assertStringContainsString(
            'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"',
            $header,
        );
        $this->assertStringContainsString('scope="openid profile"', $header);
    }

    #[TestDox('resource metadata object path and scopes are reflected in challenge')]
    public function testResourceMetadataObjectProvidesMetadataAndScopes(): void
    {
        $factory = new Psr17Factory();
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                throw new RuntimeException('Validator should not be called without a token.');
            }
        };

        $resourceMetadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['openid', 'profile'],
            metadataPaths: ['/oauth/resource-meta'],
        );

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            responseFactory: $factory,
            resourceMetadata: $resourceMetadata,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);
        $header = $response->getHeaderLine('WWW-Authenticate');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString(
            'resource_metadata="https://mcp.example.com/oauth/resource-meta"',
            $header,
        );
        $this->assertStringContainsString('scope="openid profile"', $header);
    }

    #[TestDox('authorized requests reach the handler with attributes applied')]
    public function testAllowedRequestPassesAttributes(): void
    {
        $factory = new Psr17Factory();
        $resourceMetadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow(['subject' => 'user-1']);
            }
        };

        $middleware = new AuthorizationMiddleware(
            validator: $validator,
            resourceMetadata: $resourceMetadata,
            responseFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp')
            ->withHeader('Authorization', 'Bearer token');

        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withHeader('X-Subject', (string) $request->getAttribute('subject'));
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user-1', $response->getHeaderLine('X-Subject'));
    }
}
