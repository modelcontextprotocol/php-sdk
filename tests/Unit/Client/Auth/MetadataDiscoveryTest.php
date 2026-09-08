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

use Mcp\Client\Auth\MetadataDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MetadataDiscoveryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string[]}>
     */
    public static function protectedResourceProvider(): iterable
    {
        yield 'a path is tried before the host root' => [
            'https://mcp.example.com/mcp',
            [
                'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
                'https://mcp.example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'a root endpoint has only one location' => [
            'https://mcp.example.com/',
            ['https://mcp.example.com/.well-known/oauth-protected-resource'],
        ];

        yield 'the port is part of the origin' => [
            'http://localhost:8000/api/mcp',
            [
                'http://localhost:8000/.well-known/oauth-protected-resource/api/mcp',
                'http://localhost:8000/.well-known/oauth-protected-resource',
            ],
        ];
    }

    #[DataProvider('protectedResourceProvider')]
    #[TestDox('protected resource metadata is looked for at: $_dataName')]
    public function testProtectedResourceCandidates(string $url, array $expected): void
    {
        $this->assertSame($expected, MetadataDiscovery::protectedResourceCandidates($url));
    }

    /**
     * @return iterable<string, array{string, string[]}>
     */
    public static function authorizationServerProvider(): iterable
    {
        yield 'an issuer without a path' => [
            'https://auth.example.com',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server',
                'https://auth.example.com/.well-known/openid-configuration',
            ],
        ];

        // RFC 8414 inserts the path into the well-known segment; OpenID Connect
        // discovery appends it. Both spellings exist in the wild, and the root
        // document is never asked for, since it would describe a different issuer.
        yield 'an issuer with a path' => [
            'https://auth.example.com/tenant1',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com/.well-known/openid-configuration/tenant1',
                'https://auth.example.com/tenant1/.well-known/openid-configuration',
            ],
        ];
    }

    #[DataProvider('authorizationServerProvider')]
    #[TestDox('authorization server metadata is looked for at: $_dataName')]
    public function testAuthorizationServerCandidates(string $issuer, array $expected): void
    {
        $this->assertSame($expected, MetadataDiscovery::authorizationServerCandidates($issuer));
    }

    #[TestDox('the first location that answers wins, and later ones are not requested')]
    public function testStopsAtTheFirstDocument(): void
    {
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => new Response(404),
            'https://auth.example.com/.well-known/openid-configuration' => self::json([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
            ]),
        ]);

        $metadata = $this->discovery($client)->discoverAuthorizationServer('https://auth.example.com');

        $this->assertNotNull($metadata);
        $this->assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        $this->assertCount(2, $client->requested);
    }

    #[TestDox('metadata declaring a different issuer than it was fetched for is discarded')]
    public function testRejectsIssuerMismatch(): void
    {
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'https://attacker.example.com',
                'authorization_endpoint' => 'https://attacker.example.com/authorize',
                'token_endpoint' => 'https://attacker.example.com/token',
            ]),
        ]);

        $this->assertNull($this->discovery($client)->discoverAuthorizationServer('https://auth.example.com'));
    }

    #[TestDox('a metadata URL named by the challenge is used instead of guessing')]
    public function testHonoursTheChallengeMetadataUrl(): void
    {
        $client = new RecordingClient([
            'https://mcp.example.com/custom/location.json' => self::json([
                'resource' => 'https://mcp.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
        ]);

        $metadata = $this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp', 'https://mcp.example.com/custom/location.json');

        $this->assertNotNull($metadata);
        $this->assertSame(['https://auth.example.com'], $metadata->authorizationServers);
        $this->assertSame(['https://mcp.example.com/custom/location.json'], $client->requested);
    }

    #[TestDox('a transport failure is not fatal, the next location is still tried')]
    public function testSurvivesATransportFailure(): void
    {
        $client = new RecordingClient([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => new \RuntimeException('connection refused'),
            'https://mcp.example.com/.well-known/oauth-protected-resource' => self::json([
                'resource' => 'https://mcp.example.com',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
        ]);

        $this->assertNotNull($this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp'));
    }

    private function discovery(ClientInterface $client): MetadataDiscovery
    {
        return new MetadataDiscovery($client, new Psr17Factory());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }
}

/**
 * A PSR-18 client that answers from a table and remembers what it was asked for.
 */
final class RecordingClient implements ClientInterface
{
    /** @var string[] */
    public array $requested = [];

    /**
     * @param array<string, ResponseInterface|\Throwable> $responses
     */
    public function __construct(private readonly array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        $response = $this->responses[$url] ?? new Response(404);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
