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

use Mcp\Server\Auth\ClientRegistrationResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\ClientRegistrationResponse
 */
final class ClientRegistrationResponseTest extends TestCase
{
    public function testFromArrayMinimal(): void
    {
        $data = [
            'client_id' => 'client-123',
        ];

        $response = ClientRegistrationResponse::fromArray($data);

        $this->assertSame('client-123', $response->clientId);
        $this->assertNull($response->clientSecret);
        $this->assertTrue($response->isPublicClient());
    }

    public function testFromArrayFull(): void
    {
        $data = [
            'client_id' => 'client-123',
            'client_secret' => 'secret-456',
            'client_id_issued_at' => 1700000000,
            'client_secret_expires_at' => 1800000000,
            'registration_access_token' => 'reg-token-789',
            'registration_client_uri' => 'https://auth.example.com/clients/client-123',
            'redirect_uris' => ['http://localhost:3000/callback'],
            'client_name' => 'Test Client',
            'grant_types' => ['authorization_code'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ];

        $response = ClientRegistrationResponse::fromArray($data);

        $this->assertSame('client-123', $response->clientId);
        $this->assertSame('secret-456', $response->clientSecret);
        $this->assertSame(1700000000, $response->clientIdIssuedAt);
        $this->assertSame(1800000000, $response->clientSecretExpiresAt);
        $this->assertSame('reg-token-789', $response->registrationAccessToken);
        $this->assertSame('https://auth.example.com/clients/client-123', $response->registrationClientUri);
        $this->assertFalse($response->isPublicClient());
        $this->assertTrue($response->supportsManagement());
    }

    public function testIsSecretExpired(): void
    {
        // Secret not expired
        $response = ClientRegistrationResponse::fromArray([
            'client_id' => 'client-123',
            'client_secret' => 'secret',
            'client_secret_expires_at' => time() + 3600, // 1 hour from now
        ]);
        $this->assertFalse($response->isSecretExpired());

        // Secret expired
        $response = ClientRegistrationResponse::fromArray([
            'client_id' => 'client-123',
            'client_secret' => 'secret',
            'client_secret_expires_at' => time() - 3600, // 1 hour ago
        ]);
        $this->assertTrue($response->isSecretExpired());

        // Never expires (0)
        $response = ClientRegistrationResponse::fromArray([
            'client_id' => 'client-123',
            'client_secret' => 'secret',
            'client_secret_expires_at' => 0,
        ]);
        $this->assertFalse($response->isSecretExpired());
    }

    public function testSupportsManagement(): void
    {
        // Without management
        $response = ClientRegistrationResponse::fromArray([
            'client_id' => 'client-123',
        ]);
        $this->assertFalse($response->supportsManagement());

        // With management
        $response = ClientRegistrationResponse::fromArray([
            'client_id' => 'client-123',
            'registration_access_token' => 'token',
            'registration_client_uri' => 'https://auth.example.com/clients/123',
        ]);
        $this->assertTrue($response->supportsManagement());
    }

    public function testToArray(): void
    {
        $response = new ClientRegistrationResponse(
            clientId: 'client-123',
            clientSecret: 'secret',
            redirectUris: ['http://localhost:3000/callback'],
            clientName: 'Test',
        );

        $array = $response->toArray();

        $this->assertSame('client-123', $array['client_id']);
        $this->assertSame('secret', $array['client_secret']);
        $this->assertSame(['http://localhost:3000/callback'], $array['redirect_uris']);
    }

    public function testAdditionalFields(): void
    {
        $data = [
            'client_id' => 'client-123',
            'custom_field' => 'custom_value',
            'another_field' => 123,
        ];

        $response = ClientRegistrationResponse::fromArray($data);

        $this->assertSame('custom_value', $response->additionalFields['custom_field']);
        $this->assertSame(123, $response->additionalFields['another_field']);
    }

    public function testRequiresClientId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing client_id');

        ClientRegistrationResponse::fromArray([]);
    }
}

