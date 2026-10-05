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
use Mcp\Exception\RuntimeException;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class OidcDiscoveryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideIssuers(): iterable
    {
        yield 'no path, RFC 8414' => ['https://auth.example.com', 'https://auth.example.com/.well-known/oauth-authorization-server'];
        yield 'no path, trailing slash' => ['https://auth.example.com/', 'https://auth.example.com/.well-known/oauth-authorization-server'];
        yield 'path, RFC 8414 insertion' => ['https://auth.example.com/realms/mcp', 'https://auth.example.com/.well-known/oauth-authorization-server/realms/mcp'];
        yield 'path, OIDC insertion' => ['https://auth.example.com/realms/mcp', 'https://auth.example.com/.well-known/openid-configuration/realms/mcp'];
        yield 'path, OIDC appending' => ['https://auth.example.com/tenant/v2.0/', 'https://auth.example.com/tenant/v2.0/.well-known/openid-configuration'];
        yield 'loopback http' => ['http://localhost:8180/realms/mcp', 'http://localhost:8180/realms/mcp/.well-known/openid-configuration'];
    }

    #[DataProvider('provideIssuers')]
    public function testResolvesJwksUriFromMetadata(string $issuer, string $metadataUrl): void
    {
        $client = $this->client([$metadataUrl => ['issuer' => $issuer, 'jwks_uri' => 'https://auth.example.com/jwks']]);

        $this->assertSame('https://auth.example.com/jwks', (new OidcDiscovery(new ArrayAdapter(), $client, new Psr17Factory()))->getJwksUri($issuer));
    }

    public function testCachesResolvedUri(): void
    {
        $client = $this->client(['https://auth.example.com/.well-known/oauth-authorization-server' => ['issuer' => 'https://auth.example.com', 'jwks_uri' => 'https://auth.example.com/jwks']]);
        $discovery = new OidcDiscovery(new ArrayAdapter(), $client, new Psr17Factory());

        $discovery->getJwksUri('https://auth.example.com');
        $discovery->getJwksUri('https://auth.example.com');

        $this->assertCount(1, $client->requested);
    }

    public function testSkipsMetadataOfAnotherIssuer(): void
    {
        $client = $this->client([
            'https://auth.example.com/.well-known/oauth-authorization-server' => ['issuer' => 'https://evil.example.com', 'jwks_uri' => 'https://evil.example.com/jwks'],
            'https://auth.example.com/.well-known/openid-configuration' => ['issuer' => 'https://auth.example.com', 'jwks_uri' => 'https://auth.example.com/jwks'],
        ]);

        $this->assertSame('https://auth.example.com/jwks', (new OidcDiscovery(new ArrayAdapter(), $client, new Psr17Factory()))->getJwksUri('https://auth.example.com'));
    }

    public function testRejectsInsecureJwksUri(): void
    {
        $client = $this->client(['https://auth.example.com/.well-known/oauth-authorization-server' => ['issuer' => 'https://auth.example.com', 'jwks_uri' => 'http://auth.example.com/jwks']]);

        $this->expectException(RuntimeException::class);

        (new OidcDiscovery(new ArrayAdapter(), $client, new Psr17Factory()))->getJwksUri('https://auth.example.com');
    }

    public function testRejectsInsecureIssuer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OidcDiscovery(new ArrayAdapter(), $this->client([]), new Psr17Factory()))->getJwksUri('http://auth.example.com');
    }

    public function testFailsWithoutMetadata(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to discover authorization server metadata for issuer: https://auth.example.com');

        (new OidcDiscovery(new ArrayAdapter(), $this->client([]), new Psr17Factory()))->getJwksUri('https://auth.example.com');
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     *
     * @return ClientInterface&object{requested: list<string>}
     */
    private function client(array $documents): ClientInterface
    {
        return new class($documents) implements ClientInterface {
            /** @var list<string> */
            public array $requested = [];

            /** @param array<string, array<string, mixed>> $documents */
            public function __construct(private array $documents)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory();
                $url = (string) $request->getUri();
                $this->requested[] = $url;

                if (!isset($this->documents[$url])) {
                    return $factory->createResponse(404);
                }

                return $factory->createResponse(200)->withBody($factory->createStream(json_encode($this->documents[$url], \JSON_THROW_ON_ERROR)));
            }
        };
    }
}
