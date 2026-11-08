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
use Psr\Http\Client\ClientExceptionInterface;
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

        yield 'no path has only one location' => [
            'https://mcp.example.com',
            ['https://mcp.example.com/.well-known/oauth-protected-resource'],
        ];

        yield 'a trailing slash is kept' => [
            'https://mcp.example.com/mcp/',
            [
                'https://mcp.example.com/.well-known/oauth-protected-resource/mcp/',
                'https://mcp.example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'a query is kept' => [
            'https://mcp.example.com/mcp?x=1',
            [
                'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?x=1',
                'https://mcp.example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'a trailing slash and a query are kept' => [
            'https://mcp.example.com/mcp/?tenant=a',
            [
                'https://mcp.example.com/.well-known/oauth-protected-resource/mcp/?tenant=a',
                'https://mcp.example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'a query on the root endpoint' => [
            'https://mcp.example.com/?x=1',
            [
                'https://mcp.example.com/.well-known/oauth-protected-resource?x=1',
                'https://mcp.example.com/.well-known/oauth-protected-resource',
            ],
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

        // RFC 8414 inserts the path into the well-known segment, OpenID Connect appends it.
        yield 'a terminating slash on an issuer without a path' => [
            'https://auth.example.com/',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server',
                'https://auth.example.com/.well-known/openid-configuration',
            ],
        ];

        yield 'a terminating slash on an issuer path' => [
            'https://auth.example.com/tenant1/',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com/.well-known/openid-configuration/tenant1',
                'https://auth.example.com/tenant1/.well-known/openid-configuration',
            ],
        ];

        yield 'an empty leading segment in an issuer path' => [
            'https://auth.example.com//tenant1',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server//tenant1',
                'https://auth.example.com/.well-known/openid-configuration//tenant1',
                'https://auth.example.com//tenant1/.well-known/openid-configuration',
            ],
        ];

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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function issuerMismatchFallbackProvider(): iterable
    {
        yield 'an issuer without a path' => [
            'https://auth.example.com',
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/.well-known/openid-configuration',
        ];

        yield 'an issuer with a path' => [
            'https://auth.example.com/tenant1',
            'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
            'https://auth.example.com/.well-known/openid-configuration/tenant1',
        ];
    }

    #[DataProvider('issuerMismatchFallbackProvider')]
    #[TestDox('a document naming another issuer does not stop a later location: $_dataName')]
    public function testContinuesAfterAnIssuerMismatch(string $issuer, string $mismatched, string $matching): void
    {
        $client = new RecordingClient([
            $mismatched => self::json([
                'issuer' => 'https://attacker.example.com',
                'authorization_endpoint' => 'https://attacker.example.com/authorize',
                'token_endpoint' => 'https://attacker.example.com/token',
            ]),
            $matching => self::json([
                'issuer' => $issuer,
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
            ]),
        ]);

        $metadata = $this->discovery($client)->discoverAuthorizationServer($issuer);

        $this->assertNotNull($metadata);
        $this->assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        $this->assertSame([$mismatched, $matching], $client->requested);
    }

    #[TestDox('every location is tried before giving up on a mismatching issuer')]
    public function testTriesEveryLocationOnIssuerMismatch(): void
    {
        $attacker = self::json([
            'issuer' => 'https://attacker.example.com',
            'authorization_endpoint' => 'https://attacker.example.com/authorize',
            'token_endpoint' => 'https://attacker.example.com/token',
        ]);
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => $attacker,
            'https://auth.example.com/.well-known/openid-configuration' => $attacker,
        ]);

        $this->assertNull($this->discovery($client)->discoverAuthorizationServer('https://auth.example.com'));
        $this->assertCount(2, $client->requested);
    }

    #[TestDox('an issuer that is neither https nor loopback is not even requested')]
    public function testRefusesAnInsecureIssuer(): void
    {
        $client = new RecordingClient([
            'http://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'http://auth.example.com',
                'authorization_endpoint' => 'https://attacker.example.com/authorize',
                'token_endpoint' => 'https://attacker.example.com/token',
            ]),
        ]);

        $this->assertNull($this->discovery($client)->discoverAuthorizationServer('http://auth.example.com'));
        $this->assertSame([], $client->requested);
    }

    #[TestDox('a loopback issuer may use plain http')]
    public function testAllowsALoopbackIssuer(): void
    {
        $client = new RecordingClient([
            'http://localhost:8080/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'http://localhost:8080',
                'authorization_endpoint' => 'http://localhost:8080/authorize',
                'token_endpoint' => 'http://localhost:8080/token',
            ]),
        ]);

        $this->assertNotNull($this->discovery($client)->discoverAuthorizationServer('http://localhost:8080'));
    }

    #[TestDox('metadata without an authorization endpoint is usable when no grant needs it')]
    public function testAcceptsMetadataWithoutAnAuthorizationEndpoint(): void
    {
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'https://auth.example.com',
                'token_endpoint' => 'https://auth.example.com/token',
                'grant_types_supported' => ['client_credentials'],
            ]),
        ]);

        $metadata = $this->discovery($client)->discoverAuthorizationServer('https://auth.example.com');

        $this->assertNotNull($metadata);
        $this->assertNull($metadata->authorizationEndpoint);
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

    #[TestDox('an invalid resource identifier does not stop a later location')]
    public function testContinuesAfterAnInvalidResource(): void
    {
        $path = 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp';
        $root = 'https://mcp.example.com/.well-known/oauth-protected-resource';
        $client = new RecordingClient([
            $path => self::json(['resource' => 'https://mcp.example.com/mcp#tenant-a']),
            $root => self::json(['resource' => 'https://mcp.example.com']),
        ]);

        $metadata = $this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp');

        $this->assertNotNull($metadata);
        $this->assertSame('https://mcp.example.com', $metadata->resource);
        $this->assertSame([$path, $root], $client->requested);
    }

    #[TestDox('an invalid resource identifier at a challenge URL yields nothing, without guessing')]
    public function testIgnoresAnInvalidResourceAtTheChallengeUrl(): void
    {
        $url = 'https://mcp.example.com/custom/location.json';
        $client = new RecordingClient([
            $url => self::json(['resource' => 'https://mcp.example.com/mcp#tenant-a']),
        ]);

        $this->assertNull($this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp', $url));
        $this->assertSame([$url], $client->requested);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIssuerProvider(): iterable
    {
        yield 'a query' => ['https://auth.example.com?tenant=a'];
        yield 'a fragment' => ['https://auth.example.com#tenant-a'];
    }

    #[DataProvider('invalidIssuerProvider')]
    #[TestDox('a document repeating an invalid advertised issuer is discarded: $_dataName')]
    public function testRejectsAnInvalidIssuerEvenWhenItMatches(string $issuer): void
    {
        $document = self::json([
            'issuer' => $issuer,
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
        ]);
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => $document,
            'https://auth.example.com/.well-known/openid-configuration' => $document,
        ]);

        $this->assertNull($this->discovery($client)->discoverAuthorizationServer($issuer));
    }

    #[TestDox('an invalid endpoint does not stop a later location')]
    public function testContinuesAfterAnInvalidEndpoint(): void
    {
        $client = new RecordingClient([
            'https://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize#frag',
                'token_endpoint' => 'https://auth.example.com/token',
            ]),
            'https://auth.example.com/.well-known/openid-configuration' => self::json([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/oidc/authorize',
                'token_endpoint' => 'https://auth.example.com/oidc/token',
            ]),
        ]);

        $metadata = $this->discovery($client)->discoverAuthorizationServer('https://auth.example.com');

        $this->assertNotNull($metadata);
        $this->assertSame('https://auth.example.com/oidc/authorize', $metadata->authorizationEndpoint);
    }

    #[TestDox('a metadata URL that is neither https nor loopback is not even requested')]
    public function testRefusesAnInsecureMetadataUrl(): void
    {
        $url = 'http://mcp.example.com/custom/location.json';
        $client = new RecordingClient([
            $url => self::json(['resource' => 'https://mcp.example.com/mcp']),
        ]);

        $this->assertNull($this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp', $url));
        $this->assertSame([], $client->requested);
    }

    #[TestDox('resource metadata is not guessed over plain http')]
    public function testRefusesInsecureResourceMetadataLocations(): void
    {
        $client = new RecordingClient([
            'http://mcp.example.com/.well-known/oauth-protected-resource' => self::json(['resource' => 'https://mcp.example.com']),
        ]);

        $this->assertNull($this->discovery($client)->discoverProtectedResource('http://mcp.example.com/mcp'));
        $this->assertSame([], $client->requested);
    }

    #[TestDox('resource metadata on a loopback host may use plain http')]
    public function testAllowsLoopbackResourceMetadata(): void
    {
        $client = new RecordingClient([
            'http://localhost:8000/.well-known/oauth-protected-resource/mcp' => self::json(['resource' => 'http://localhost:8000/mcp']),
        ]);

        $this->assertNotNull($this->discovery($client)->discoverProtectedResource('http://localhost:8000/mcp'));
    }

    #[TestDox('a transport failure is not fatal, the next location is still tried')]
    public function testSurvivesATransportFailure(): void
    {
        $client = new RecordingClient([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {},
            'https://mcp.example.com/.well-known/oauth-protected-resource' => self::json([
                'resource' => 'https://mcp.example.com',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
        ]);

        $this->assertNotNull($this->discovery($client)->discoverProtectedResource('https://mcp.example.com/mcp'));
    }

    #[TestDox('a legacy server gets its fixed endpoints only when every metadata location answers 404')]
    public function testLegacyDiscoveryGuessesAfterA404(): void
    {
        $metadata = $this->discovery(self::answering(new Response(404)))->discoverLegacyAuthorizationServer('https://mcp.example.com');

        $this->assertSame('https://mcp.example.com/token', $metadata?->tokenEndpoint);
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function brokenMetadataProvider(): iterable
    {
        yield 'a server error' => [new Response(500)];
        yield 'a forbidden answer' => [new Response(403)];
        yield 'a redirect' => [new Response(307, ['Location' => 'http://evil.example.com/'])];
        yield 'another issuer' => [self::json([
            'issuer' => 'https://evil.example.com',
            'authorization_endpoint' => 'https://evil.example.com/authorize',
            'token_endpoint' => 'https://evil.example.com/token',
        ])];
        yield 'a malformed document' => [self::json(['issuer' => 'https://mcp.example.com'])];
    }

    #[DataProvider('brokenMetadataProvider')]
    #[TestDox('a legacy server keeps its metadata problem and gets no guessed endpoints: $_dataName')]
    public function testLegacyDiscoveryDoesNotGuessAfterAnotherAnswer(Response $response): void
    {
        $this->assertNull($this->discovery(self::answering($response))->discoverLegacyAuthorizationServer('https://mcp.example.com'));
    }

    #[TestDox('a legacy server that answers 404 at one location and fails at the next gets no guessed endpoints')]
    public function testLegacyDiscoveryNeedsA404FromEveryLocation(): void
    {
        $client = new class implements ClientInterface {
            private int $requests = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(1 === ++$this->requests ? 404 : 500);
            }
        };

        $this->assertNull($this->discovery($client)->discoverLegacyAuthorizationServer('https://mcp.example.com'));
    }

    #[TestDox('a legacy server that cannot be reached gets no guessed endpoints')]
    public function testLegacyDiscoveryDoesNotGuessAfterATransportFailure(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('unreachable') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        $this->assertNull($this->discovery($client)->discoverLegacyAuthorizationServer('https://mcp.example.com'));
    }

    #[TestDox('a legacy server that publishes metadata keeps it')]
    public function testLegacyDiscoveryPrefersPublishedMetadata(): void
    {
        $client = self::answering(self::json([
            'issuer' => 'https://mcp.example.com',
            'authorization_endpoint' => 'https://mcp.example.com/oauth/authorize',
            'token_endpoint' => 'https://mcp.example.com/oauth/token',
        ]));

        $this->assertSame('https://mcp.example.com/oauth/token', $this->discovery($client)->discoverLegacyAuthorizationServer('https://mcp.example.com')?->tokenEndpoint);
    }

    private static function answering(ResponseInterface $response): ClientInterface
    {
        return new class($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
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
