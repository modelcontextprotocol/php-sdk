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

use Mcp\Exception\ClientRegistrationException;
use Mcp\Server\Transport\Http\OAuth\Client;
use Mcp\Server\Transport\Http\OAuth\InMemoryClientRepository;
use Mcp\Server\Transport\Http\OAuth\StoredClientRegistrar;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class StoredClientRegistrarTest extends TestCase
{
    #[TestDox('registers a confidential client with a generated id and secret, persisted to the repository')]
    public function testRegistersConfidentialClient(): void
    {
        $repository = new InMemoryClientRepository();
        $registrar = new StoredClientRegistrar($repository, ['mcp:tools', 'mcp:resources']);

        $response = $registrar->register([
            'redirect_uris' => ['https://client.example.com/callback'],
            'client_name' => 'My Client',
        ]);

        $this->assertArrayHasKey('client_id', $response);
        $this->assertArrayHasKey('client_secret', $response);
        $this->assertSame('My Client', $response['client_name']);
        $this->assertContains('authorization_code', $response['grant_types']);
        $this->assertContains('refresh_token', $response['grant_types']);

        $stored = $repository->find($response['client_id']);
        $this->assertInstanceOf(Client::class, $stored);
        $this->assertFalse($stored->isPublic());
    }

    #[TestDox('registers a public client (token_endpoint_auth_method=none) without a secret')]
    public function testRegistersPublicClient(): void
    {
        $repository = new InMemoryClientRepository();
        $registrar = new StoredClientRegistrar($repository);

        $response = $registrar->register([
            'redirect_uris' => ['https://client.example.com/callback'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $this->assertArrayNotHasKey('client_secret', $response);
        $this->assertSame('none', $response['token_endpoint_auth_method']);
        $this->assertTrue($repository->find($response['client_id'])?->isPublic());
    }

    #[TestDox('allows http loopback redirect URIs')]
    public function testAllowsLoopbackRedirect(): void
    {
        $registrar = new StoredClientRegistrar(new InMemoryClientRepository());

        $response = $registrar->register(['redirect_uris' => ['http://localhost:1234/cb', 'http://127.0.0.1/cb']]);

        $this->assertCount(2, $response['redirect_uris']);
    }

    #[TestDox('rejects a disallowed redirect URI scheme')]
    public function testRejectsDisallowedScheme(): void
    {
        $registrar = new StoredClientRegistrar(new InMemoryClientRepository());

        try {
            $registrar->register(['redirect_uris' => ['http://evil.example.com/cb']]);
            $this->fail('Expected ClientRegistrationException');
        } catch (ClientRegistrationException $e) {
            $this->assertSame('invalid_redirect_uri', $e->errorCode);
        }
    }

    #[TestDox('rejects missing redirect URIs')]
    public function testRejectsMissingRedirectUris(): void
    {
        $registrar = new StoredClientRegistrar(new InMemoryClientRepository());

        $this->expectException(ClientRegistrationException::class);
        $registrar->register(['client_name' => 'No Redirects']);
    }
}
