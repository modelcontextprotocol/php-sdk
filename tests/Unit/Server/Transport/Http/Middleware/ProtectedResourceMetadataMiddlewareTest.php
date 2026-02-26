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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests ProtectedResourceMetadataMiddleware responses for metadata endpoints.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class ProtectedResourceMetadataMiddlewareTest extends TestCase
{
    #[TestDox('default metadata endpoint returns protected resource metadata JSON')]
    public function testDefaultMetadataEndpointReturnsJson(): void
    {
        $factory = new Psr17Factory();

        $metadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['mcp:read', 'mcp:write'],
            resource: 'https://mcp.example.com/mcp',
            resourceName: 'Example MCP API',
            resourceDocumentation: 'https://mcp.example.com/docs',
            localizedHumanReadable: [
                'resource_name#uk' => 'Pryklad MCP API',
            ],
        );

        $middleware = new ProtectedResourceMetadataMiddleware(
            metadata: $metadata,
            responseFactory: $factory,
            streamFactory: $factory,
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
                return $this->factory->createResponse(404);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['https://auth.example.com'], $payload['authorization_servers']);
        $this->assertSame(['mcp:read', 'mcp:write'], $payload['scopes_supported']);
        $this->assertSame('https://mcp.example.com/mcp', $payload['resource']);
        $this->assertSame('Example MCP API', $payload['resource_name']);
        $this->assertSame('https://mcp.example.com/docs', $payload['resource_documentation']);
        $this->assertSame('Pryklad MCP API', $payload['resource_name#uk']);
    }

    #[TestDox('non metadata request passes to next middleware')]
    public function testNonMetadataRequestPassesThrough(): void
    {
        $factory = new Psr17Factory();

        $metadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
        );

        $middleware = new ProtectedResourceMetadataMiddleware(
            metadata: $metadata,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');

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

    #[TestDox('empty authorization servers are rejected')]
    public function testEmptyAuthorizationServersThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one authorization server');

        new ProtectedResourceMetadata([]);
    }
}
