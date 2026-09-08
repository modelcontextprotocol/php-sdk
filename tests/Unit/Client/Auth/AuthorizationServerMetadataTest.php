<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\AuthorizationServerMetadata;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class AuthorizationServerMetadataTest extends TestCase
{
    #[TestDox('the fields the client acts on are read out of the document')]
    public function testFromArray(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'registration_endpoint' => 'https://auth.example.com/register',
            'scopes_supported' => ['mcp:read', 'offline_access'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);

        $this->assertSame('https://auth.example.com/register', $metadata->registrationEndpoint);
        $this->assertTrue($metadata->clientIdMetadataDocumentSupported);
        $this->assertTrue($metadata->issuerParameterSupported);
        $this->assertTrue($metadata->supportsScope('offline_access'));
        $this->assertFalse($metadata->supportsScope('mcp:write'));
    }

    #[TestDox('a document missing an endpoint the client needs is rejected')]
    public function testRequiresEndpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
        ]);
    }

    #[TestDox('an unstated grant list means the authorization code grant, and nothing else')]
    public function testDefaultGrantTypes(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
        ]);

        $this->assertTrue($metadata->supportsGrant('authorization_code'));
        $this->assertFalse($metadata->supportsGrant('refresh_token'));
    }

    // These URLs come out of a document whose location a hostile MCP server chose, and
    // one of them is opened in the user's browser.
    #[TestDox('endpoints that are not HTTPS are refused')]
    public function testRejectsInsecureEndpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('neither an HTTPS URL nor a loopback address');

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'http://auth.example.com/token',
        ]);
    }

    #[DataProvider('loopbackProvider')]
    #[TestDox('plain HTTP is allowed on a loopback address: $_dataName')]
    public function testAllowsLoopbackOverHttp(string $endpoint): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'http://localhost:8080',
            'authorization_endpoint' => $endpoint.'/authorize',
            'token_endpoint' => $endpoint.'/token',
        ]);

        $this->assertSame($endpoint.'/token', $metadata->tokenEndpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackProvider(): iterable
    {
        yield 'localhost' => ['http://localhost:8080'];
        yield 'the loopback address' => ['http://127.0.0.1:8080'];
        yield 'anywhere in 127/8' => ['http://127.13.2.9:8080'];
        yield 'IPv6 loopback' => ['http://[::1]:8080'];
    }

    #[TestDox('a host that merely looks like loopback is still refused')]
    public function testRejectsLookalikeHosts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'http://localhost.evil.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
        ]);
    }

    #[TestDox('a server that publishes nothing gets the endpoint names its era prescribed')]
    public function testLegacyEndpoints(): void
    {
        $metadata = AuthorizationServerMetadata::forLegacyServer('https://mcp.example.com');

        $this->assertSame('https://mcp.example.com/authorize', $metadata->authorizationEndpoint);
        $this->assertSame('https://mcp.example.com/token', $metadata->tokenEndpoint);
        $this->assertSame('https://mcp.example.com/register', $metadata->registrationEndpoint);
        $this->assertFalse($metadata->issuerParameterSupported);
    }
}
