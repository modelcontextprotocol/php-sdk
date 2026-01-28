<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Middleware;

use Mcp\Server\Transport\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Middleware\AuthorizationResult;
use Mcp\Server\Transport\Middleware\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Middleware\ProtectedResourceMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthorizationMiddlewareTest extends TestCase
{
    #[TestDox('missing Authorization header returns 401 with metadata and scope guidance')]
    public function testMissingAuthorizationReturns401(): void
    {
        $factory = new Psr17Factory();
        $metadata = new ProtectedResourceMetadata(['https://auth.example.com'], ['mcp:read']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
            {
                throw new \RuntimeException('Validator should not be called without a token.');
            }
        };

        $middleware = new AuthorizationMiddleware(
            $metadata,
            $validator,
            $factory,
            $factory,
            ['/.well-known/oauth-protected-resource'],
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            static function (): array {
                return ['mcp:read'];
            },
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
        $metadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow();
            }
        };

        $middleware = new AuthorizationMiddleware(
            $metadata,
            $validator,
            $factory,
            $factory,
            ['/.well-known/oauth-protected-resource'],
            'https://mcp.example.com/.well-known/oauth-protected-resource',
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
        $metadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::forbidden('insufficient_scope', 'Need more scopes.', ['mcp:write']);
            }
        };

        $middleware = new AuthorizationMiddleware(
            $metadata,
            $validator,
            $factory,
            $factory,
            ['/.well-known/oauth-protected-resource'],
            'https://mcp.example.com/.well-known/oauth-protected-resource',
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

    #[TestDox('metadata endpoint returns protected resource metadata JSON')]
    public function testMetadataEndpointReturnsJson(): void
    {
        $factory = new Psr17Factory();
        $metadata = new ProtectedResourceMetadata(
            ['https://auth.example.com'],
            ['mcp:read', 'mcp:write'],
        );
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow();
            }
        };

        $middleware = new AuthorizationMiddleware(
            $metadata,
            $validator,
            $factory,
            $factory,
            ['/.well-known/oauth-protected-resource'],
        );

        $request = $factory->createServerRequest(
            'GET',
            'https://mcp.example.com/.well-known/oauth-protected-resource',
        );

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

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['https://auth.example.com'], $payload['authorization_servers']);
        $this->assertSame(['mcp:read', 'mcp:write'], $payload['scopes_supported']);
    }

    #[TestDox('authorized requests reach the handler with attributes applied')]
    public function testAllowedRequestPassesAttributes(): void
    {
        $factory = new Psr17Factory();
        $metadata = new ProtectedResourceMetadata(['https://auth.example.com']);
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow(['subject' => 'user-1']);
            }
        };

        $middleware = new AuthorizationMiddleware(
            $metadata,
            $validator,
            $factory,
            $factory,
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
