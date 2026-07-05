<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\OAuth;

use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tests the standalone RFC 9728 metadata request handler.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class ProtectedResourceMetadataHandlerTest extends TestCase
{
    #[TestDox('handle returns protected resource metadata JSON')]
    public function testHandleReturnsMetadataJson(): void
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

        $handler = new ProtectedResourceMetadataHandler(
            metadata: $metadata,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $request = $factory->createServerRequest(
            'GET',
            'https://mcp.example.com/.well-known/oauth-protected-resource',
        );

        $response = $handler->handle($request);

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

    #[TestDox('handle serves metadata regardless of request path or method')]
    public function testHandleIgnoresRoutingConcerns(): void
    {
        $factory = new Psr17Factory();

        $handler = new ProtectedResourceMetadataHandler(
            metadata: new ProtectedResourceMetadata(
                authorizationServers: ['https://auth.example.com'],
            ),
            responseFactory: $factory,
            streamFactory: $factory,
        );

        // The handler is the "controller action" — the caller (middleware or framework
        // router) owns path/method matching, so an arbitrary request still yields metadata.
        $request = $factory->createServerRequest('POST', 'https://mcp.example.com/anything');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['https://auth.example.com'], $payload['authorization_servers']);
    }
}
