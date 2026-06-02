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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationServerMetadata;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class AuthorizationServerMetadataTest extends TestCase
{
    #[TestDox('derives endpoints from the issuer and omits registration_endpoint by default')]
    public function testDefaults(): void
    {
        $metadata = new AuthorizationServerMetadata('https://mcp.example.com/');

        $data = $metadata->jsonSerialize();

        $this->assertSame('https://mcp.example.com', $data['issuer']);
        $this->assertSame('https://mcp.example.com/authorize', $data['authorization_endpoint']);
        $this->assertSame('https://mcp.example.com/token', $data['token_endpoint']);
        $this->assertSame('https://mcp.example.com/.well-known/jwks.json', $data['jwks_uri']);
        $this->assertSame(['S256'], $data['code_challenge_methods_supported']);
        $this->assertArrayNotHasKey('registration_endpoint', $data);
    }

    #[TestDox('includes registration_endpoint and extra fields when provided')]
    public function testOverrides(): void
    {
        $metadata = new AuthorizationServerMetadata(
            issuer: 'https://mcp.example.com',
            registrationEndpoint: 'https://mcp.example.com/register',
            scopesSupported: ['mcp:tools'],
            extra: ['service_documentation' => 'https://docs.example.com'],
        );

        $data = $metadata->jsonSerialize();

        $this->assertSame('https://mcp.example.com/register', $data['registration_endpoint']);
        $this->assertSame(['mcp:tools'], $data['scopes_supported']);
        $this->assertSame('https://docs.example.com', $data['service_documentation']);
    }

    #[TestDox('rejects an empty issuer')]
    public function testEmptyIssuer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthorizationServerMetadata('   ');
    }
}
