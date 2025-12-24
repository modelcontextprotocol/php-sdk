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

use Mcp\Server\Auth\ClientRegistration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\ClientRegistration
 */
final class ClientRegistrationTest extends TestCase
{
    public function testMinimalRegistration(): void
    {
        $registration = new ClientRegistration(
            redirectUris: ['http://localhost:3000/callback'],
        );

        $json = $registration->jsonSerialize();

        $this->assertSame(['http://localhost:3000/callback'], $json['redirect_uris']);
        $this->assertSame(['authorization_code'], $json['grant_types']);
        $this->assertSame(['code'], $json['response_types']);
        $this->assertSame('none', $json['token_endpoint_auth_method']);
    }

    public function testForPublicClient(): void
    {
        $registration = ClientRegistration::forPublicClient(
            redirectUris: ['http://localhost:3000/callback', 'http://127.0.0.1:3000/callback'],
            clientName: 'My MCP Client',
            clientUri: 'https://example.com',
            scope: 'mcp:read mcp:write',
        );

        $json = $registration->jsonSerialize();

        $this->assertCount(2, $json['redirect_uris']);
        $this->assertSame('My MCP Client', $json['client_name']);
        $this->assertSame('https://example.com', $json['client_uri']);
        $this->assertSame('mcp:read mcp:write', $json['scope']);
        $this->assertSame('none', $json['token_endpoint_auth_method']);
        $this->assertContains('refresh_token', $json['grant_types']);
    }

    public function testForConfidentialClient(): void
    {
        $registration = ClientRegistration::forConfidentialClient(
            redirectUris: ['https://app.example.com/callback'],
            clientName: 'Server App',
            tokenEndpointAuthMethod: 'client_secret_basic',
        );

        $json = $registration->jsonSerialize();

        $this->assertSame('client_secret_basic', $json['token_endpoint_auth_method']);
        $this->assertContains('client_credentials', $json['grant_types']);
    }

    public function testRequiresRedirectUris(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one redirect URI is required');

        new ClientRegistration(redirectUris: []);
    }

    public function testFullRegistration(): void
    {
        $registration = new ClientRegistration(
            redirectUris: ['https://app.example.com/callback'],
            clientName: 'Full Client',
            clientUri: 'https://example.com',
            logoUri: 'https://example.com/logo.png',
            grantTypes: ['authorization_code', 'refresh_token'],
            responseTypes: ['code'],
            scope: 'openid profile mcp:read',
            contacts: ['admin@example.com'],
            tosUri: 'https://example.com/tos',
            policyUri: 'https://example.com/privacy',
            softwareId: 'mcp-client-001',
            softwareVersion: '1.0.0',
        );

        $json = $registration->jsonSerialize();

        $this->assertSame('https://example.com/logo.png', $json['logo_uri']);
        $this->assertSame(['admin@example.com'], $json['contacts']);
        $this->assertSame('https://example.com/tos', $json['tos_uri']);
        $this->assertSame('https://example.com/privacy', $json['policy_uri']);
        $this->assertSame('mcp-client-001', $json['software_id']);
        $this->assertSame('1.0.0', $json['software_version']);
    }

    public function testFromArray(): void
    {
        $data = [
            'redirect_uris' => ['http://localhost:3000/callback'],
            'client_name' => 'Test Client',
            'grant_types' => ['authorization_code'],
            'token_endpoint_auth_method' => 'none',
        ];

        $registration = ClientRegistration::fromArray($data);

        $this->assertSame(['http://localhost:3000/callback'], $registration->redirectUris);
        $this->assertSame('Test Client', $registration->clientName);
    }

    public function testJsonEncode(): void
    {
        $registration = ClientRegistration::forPublicClient(
            redirectUris: ['http://localhost:3000/callback'],
            clientName: 'Test',
        );

        $json = json_encode($registration, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('redirect_uris', $decoded);
        $this->assertArrayHasKey('client_name', $decoded);
    }
}

