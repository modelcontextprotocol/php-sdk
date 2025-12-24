<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Auth;

use Mcp\Server\Auth\ProtectedResourceMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\ProtectedResourceMetadata
 */
final class ProtectedResourceMetadataTest extends TestCase
{
    public function testMinimalMetadata(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth.example.com'],
        );

        $json = $metadata->jsonSerialize();

        $this->assertSame('https://mcp.example.com', $json['resource']);
        $this->assertSame(['https://auth.example.com'], $json['authorization_servers']);
        $this->assertArrayHasKey('bearer_methods_supported', $json);
        $this->assertSame(['header'], $json['bearer_methods_supported']);
    }

    public function testFullMetadata(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth1.example.com', 'https://auth2.example.com'],
            scopesSupported: ['read', 'write', 'admin'],
            bearerMethodsSupported: ['header'],
            resourceDocumentation: 'https://docs.example.com',
            resourceName: 'MCP Server',
        );

        $json = $metadata->jsonSerialize();

        $this->assertSame('https://mcp.example.com', $json['resource']);
        $this->assertCount(2, $json['authorization_servers']);
        $this->assertSame(['read', 'write', 'admin'], $json['scopes_supported']);
        $this->assertSame('https://docs.example.com', $json['resource_documentation']);
        $this->assertSame('MCP Server', $json['resource_name']);
    }

    public function testRequiresAtLeastOneAuthorizationServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one authorization server must be specified');

        new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: [],
        );
    }

    public function testJsonEncode(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['read', 'write'],
        );

        $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        $this->assertSame('https://mcp.example.com', $decoded['resource']);
        $this->assertSame(['https://auth.example.com'], $decoded['authorization_servers']);
        $this->assertSame(['read', 'write'], $decoded['scopes_supported']);
    }

    public function testOmitsNullValues(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: null,
            resourceDocumentation: null,
            resourceName: null,
        );

        $json = $metadata->jsonSerialize();

        $this->assertArrayNotHasKey('scopes_supported', $json);
        $this->assertArrayNotHasKey('resource_documentation', $json);
        $this->assertArrayNotHasKey('resource_name', $json);
    }
}

