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

use Mcp\Server\Auth\AuthorizationServerMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\AuthorizationServerMetadata
 */
final class AuthorizationServerMetadataTest extends TestCase
{
    public function testFromArrayMinimal(): void
    {
        $data = [
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
        ];

        $metadata = AuthorizationServerMetadata::fromArray($data);

        $this->assertSame('https://auth.example.com', $metadata->issuer);
        $this->assertSame('https://auth.example.com/authorize', $metadata->authorizationEndpoint);
        $this->assertNull($metadata->tokenEndpoint);
        $this->assertFalse($metadata->supportsPkce());
    }

    public function testFromArrayFull(): void
    {
        $data = [
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/.well-known/jwks.json',
            'registration_endpoint' => 'https://auth.example.com/register',
            'scopes_supported' => ['openid', 'profile', 'mcp:read'],
            'response_types_supported' => ['code', 'token'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'introspection_endpoint' => 'https://auth.example.com/introspect',
            'client_id_metadata_document_supported' => true,
        ];

        $metadata = AuthorizationServerMetadata::fromArray($data);

        $this->assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        $this->assertSame('https://auth.example.com/.well-known/jwks.json', $metadata->jwksUri);
        $this->assertSame('https://auth.example.com/register', $metadata->registrationEndpoint);
        $this->assertTrue($metadata->supportsDynamicRegistration());
        $this->assertTrue($metadata->supportsPkce());
        $this->assertTrue($metadata->supportsS256());
        $this->assertTrue($metadata->supportsClientIdMetadataDocument());
    }

    public function testSupportsPkce(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'code_challenge_methods_supported' => ['S256'],
        ]);

        $this->assertTrue($metadata->supportsPkce());
        $this->assertTrue($metadata->supportsS256());
    }

    public function testSupportsGrantType(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]);

        $this->assertTrue($metadata->supportsGrantType('authorization_code'));
        $this->assertTrue($metadata->supportsGrantType('refresh_token'));
        $this->assertFalse($metadata->supportsGrantType('client_credentials'));
    }

    public function testDefaultGrantTypes(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            // No grant_types_supported - uses defaults per RFC 8414
        ]);

        $this->assertTrue($metadata->supportsGrantType('authorization_code'));
        $this->assertTrue($metadata->supportsGrantType('implicit'));
        $this->assertFalse($metadata->supportsGrantType('client_credentials'));
    }

    public function testRequiresIssuer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing issuer');

        AuthorizationServerMetadata::fromArray([
            'authorization_endpoint' => 'https://auth.example.com/authorize',
        ]);
    }

    public function testRequiresAuthorizationEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing authorization_endpoint');

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
        ]);
    }

    public function testAdditionalFields(): void
    {
        $data = [
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'custom_field' => 'custom_value',
        ];

        $metadata = AuthorizationServerMetadata::fromArray($data);

        $this->assertSame('custom_value', $metadata->additionalFields['custom_field']);
    }
}

