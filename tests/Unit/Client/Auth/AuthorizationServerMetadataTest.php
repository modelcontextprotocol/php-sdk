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

    #[TestDox('the authorization endpoint may be absent when no supported grant uses it')]
    public function testAuthorizationEndpointIsOptionalWithoutARedirectGrant(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'token_endpoint' => 'https://auth.example.com/token',
            'grant_types_supported' => ['client_credentials'],
        ]);

        $this->assertNull($metadata->authorizationEndpoint);
        $this->assertTrue($metadata->supportsGrant('client_credentials'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authorizationEndpointRequiredProvider(): iterable
    {
        yield 'no grant list' => [[]];
        yield 'a null grant list' => [['grant_types_supported' => null]];
        yield 'the authorization code grant' => [['grant_types_supported' => ['client_credentials', 'authorization_code']]];
        yield 'the implicit grant' => [['grant_types_supported' => ['implicit']]];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('authorizationEndpointRequiredProvider')]
    #[TestDox('the authorization endpoint is required for: $_dataName')]
    public function testAuthorizationEndpointIsRequiredForARedirectGrant(array $document): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorization_endpoint');

        AuthorizationServerMetadata::fromArray($document + [
            'issuer' => 'https://auth.example.com',
            'token_endpoint' => 'https://auth.example.com/token',
        ]);
    }

    #[TestDox('an insecure authorization endpoint is refused even when no grant uses it')]
    public function testRejectsAnInsecureUnusedAuthorizationEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('neither an HTTPS URL nor a loopback address');

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'http://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'grant_types_supported' => ['client_credentials'],
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

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function noUsableGrantListProvider(): iterable
    {
        yield 'an empty list' => [[]];
        yield 'a bare string' => ['authorization_code'];
        yield 'a list without strings' => [[123]];
    }

    #[DataProvider('noUsableGrantListProvider')]
    #[TestDox('a stated grant list does not fall back to the default: $_dataName')]
    public function testStatedGrantListHasNoDefault(mixed $grantTypes): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'grant_types_supported' => $grantTypes,
        ]);

        $this->assertFalse($metadata->supportsGrant('authorization_code'));
        $this->assertFalse($metadata->supportsGrant('refresh_token'));
    }

    #[TestDox('an empty grant list needs no authorization endpoint')]
    public function testEmptyGrantListNeedsNoAuthorizationEndpoint(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'token_endpoint' => 'https://auth.example.com/token',
            'grant_types_supported' => [],
        ]);

        $this->assertNull($metadata->authorizationEndpoint);
        $this->assertFalse($metadata->supportsGrant('authorization_code'));
    }

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
        yield 'a name under .localhost' => ['http://mcp.localhost:8080'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lookalikeProvider(): iterable
    {
        yield 'localhost as a subdomain' => ['http://localhost.evil.example.com'];
        yield 'not an IP address in 127/8' => ['http://127.999.1.1'];
    }

    #[DataProvider('lookalikeProvider')]
    #[TestDox('a host that merely looks like loopback is still refused: $_dataName')]
    public function testRejectsLookalikeHosts(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => $endpoint.'/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
        ]);
    }

    #[TestDox('legacy endpoints are not built on an insecure issuer')]
    public function testRefusesAnInsecureLegacyIssuer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('neither an HTTPS URL nor a loopback address');

        AuthorizationServerMetadata::forLegacyServer('http://mcp.example.com');
    }

    #[TestDox('legacy endpoints may use plain HTTP on a loopback address')]
    public function testAllowsALoopbackLegacyIssuer(): void
    {
        $metadata = AuthorizationServerMetadata::forLegacyServer('http://localhost:8080');

        $this->assertSame('http://localhost:8080/token', $metadata->tokenEndpoint);
    }

    #[TestDox('a server that publishes nothing gets the endpoint names its era prescribed')]
    public function testLegacyEndpoints(): void
    {
        $metadata = AuthorizationServerMetadata::forLegacyServer('https://mcp.example.com');

        $this->assertSame('https://mcp.example.com/authorize', $metadata->authorizationEndpoint);
        $this->assertSame('https://mcp.example.com/token', $metadata->tokenEndpoint);
        $this->assertSame('https://mcp.example.com/register', $metadata->registrationEndpoint);
        $this->assertFalse($metadata->issuerParameterSupported);
        $this->assertTrue($metadata->supportsGrant('authorization_code'));
        $this->assertFalse($metadata->supportsGrant('refresh_token'));
    }
}
