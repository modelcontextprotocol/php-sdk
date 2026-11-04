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
use Mcp\Client\Auth\ClientMetadata;
use Mcp\Client\Auth\ClientRegistrar;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Mcp\Exception\InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientRegistrationTest extends TestCase
{
    #[TestDox('a server that echoes an authentication method has the final say')]
    public function testResponseAuthMethodWins(): void
    {
        $registration = ClientRegistration::fromResponse([
            'client_id' => 'issued',
            'client_secret' => 'secret',
            'token_endpoint_auth_method' => 'client_secret_post',
        ], TokenEndpointAuthMethod::ClientSecretBasic);

        $this->assertSame(TokenEndpointAuthMethod::ClientSecretPost, $registration->tokenEndpointAuthMethod);
        $this->assertTrue($registration->dynamic);
    }

    #[TestDox('a silent server is taken to have accepted what was asked for')]
    public function testFallsBackToTheRequestedAuthMethod(): void
    {
        $registration = ClientRegistration::fromResponse(['client_id' => 'issued', 'client_secret' => 'secret'], TokenEndpointAuthMethod::None);

        $this->assertSame(TokenEndpointAuthMethod::None, $registration->tokenEndpointAuthMethod);
    }

    #[TestDox('a response with no client id is rejected')]
    public function testRequiresAClientId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientRegistration::fromResponse(['client_secret' => 'secret']);
    }

    #[TestDox('a secret with an expiry is treated as expired once it passes')]
    public function testSecretExpiry(): void
    {
        $registration = ClientRegistration::fromResponse(['client_id' => 'issued', 'client_secret_expires_at' => 1000]);

        $this->assertFalse($registration->isExpired(999));
        $this->assertTrue($registration->isExpired(1001));
    }

    #[TestDox('a secret that never expires is reported as 0, and is not an expiry')]
    public function testNonExpiringSecret(): void
    {
        $this->assertFalse(ClientRegistration::fromResponse(['client_id' => 'issued', 'client_secret_expires_at' => 0])->isExpired(\PHP_INT_MAX));
    }

    #[TestDox('registration posts what the client is and what it intends to do')]
    public function testRegistrationBody(): void
    {
        $server = new CapturingRegistrationServer();
        $factory = new Psr17Factory();

        $registration = (new ClientRegistrar($server, $factory, $factory))->register(
            $this->metadata('https://auth.example.com/register'),
            new ClientMetadata('My App', ['http://127.0.0.1:8765/callback']),
            TokenEndpointAuthMethod::ClientSecretBasic,
            'mcp:read',
        );

        $this->assertSame('My App', $server->body['client_name'] ?? null);
        $this->assertSame(['http://127.0.0.1:8765/callback'], $server->body['redirect_uris'] ?? null);
        $this->assertSame(['code'], $server->body['response_types'] ?? null);
        $this->assertSame('native', $server->body['application_type'] ?? null);
        $this->assertSame('client_secret_basic', $server->body['token_endpoint_auth_method'] ?? null);
        $this->assertSame('mcp:read', $server->body['scope'] ?? null);
        $this->assertSame('registered', $registration->clientId);
    }

    #[TestDox('a server without a registration endpoint says so plainly')]
    public function testMissingRegistrationEndpoint(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('does not support dynamic client registration');

        (new ClientRegistrar(new CapturingRegistrationServer(), $factory, $factory))->register(
            $this->metadata(null),
            new ClientMetadata('My App'),
            TokenEndpointAuthMethod::None,
        );
    }

    private function metadata(?string $registrationEndpoint): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            'https://auth.example.com',
            'https://auth.example.com/authorize',
            'https://auth.example.com/token',
            $registrationEndpoint,
        );
    }
}

final class CapturingRegistrationServer implements ClientInterface
{
    /** @var array<string, mixed> */
    public array $body = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->body = json_decode((string) $request->getBody(), true) ?: [];

        return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(['client_id' => 'registered', 'client_secret' => 'issued-secret']));
    }
}
