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
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtectedResourceMetadataTest extends TestCase
{
    public function testSerializesRfc9728Document(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com/mcp',
            authorizationServers: ['https://auth.example.com', 'https://auth.example.com'],
            scopesSupported: ['mcp:read'],
            resourceName: 'Example',
        );

        $this->assertSame([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
            'scopes_supported' => ['mcp:read'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Example',
        ], $metadata->jsonSerialize());
    }

    public function testOmitsEmptyScopes(): void
    {
        $metadata = new ProtectedResourceMetadata('https://mcp.example.com', ['https://auth.example.com'], []);

        $this->assertNull($metadata->getScopesSupported());
        $this->assertArrayNotHasKey('scopes_supported', $metadata->jsonSerialize());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideResources(): iterable
    {
        yield 'root' => ['https://mcp.example.com', '/.well-known/oauth-protected-resource', 'https://mcp.example.com/.well-known/oauth-protected-resource'];
        yield 'trailing slash' => ['https://mcp.example.com/', '/.well-known/oauth-protected-resource', 'https://mcp.example.com/.well-known/oauth-protected-resource'];
        yield 'path' => ['https://mcp.example.com/mcp', '/.well-known/oauth-protected-resource/mcp', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp'];
        yield 'port' => ['http://localhost:8000/mcp', '/.well-known/oauth-protected-resource/mcp', 'http://localhost:8000/.well-known/oauth-protected-resource/mcp'];
        yield 'query' => ['https://mcp.example.com/mcp?tenant=a', '/.well-known/oauth-protected-resource/mcp', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?tenant=a'];
    }

    #[DataProvider('provideResources')]
    public function testDerivesMetadataLocationFromResource(string $resource, string $path, string $url): void
    {
        $metadata = new ProtectedResourceMetadata($resource, ['https://auth.example.com']);

        $this->assertSame($path, $metadata->getMetadataPath());
        $this->assertSame($url, $metadata->getMetadataUrl());
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>|null}>
     */
    public static function provideInvalidArguments(): iterable
    {
        yield 'relative resource' => ['/mcp', ['https://auth.example.com'], null];
        yield 'insecure resource' => ['http://mcp.example.com', ['https://auth.example.com'], null];
        yield 'resource with fragment' => ['https://mcp.example.com#a', ['https://auth.example.com'], null];
        yield 'no authorization server' => ['https://mcp.example.com', [], null];
        yield 'insecure authorization server' => ['https://mcp.example.com', ['http://auth.example.com'], null];
        yield 'scope with whitespace' => ['https://mcp.example.com', ['https://auth.example.com'], ['a b']];
        yield 'scope with quote' => ['https://mcp.example.com', ['https://auth.example.com'], ['a"']];
    }

    /**
     * @param list<string>      $authorizationServers
     * @param list<string>|null $scopes
     */
    #[DataProvider('provideInvalidArguments')]
    public function testRejectsInvalidArguments(string $resource, array $authorizationServers, ?array $scopes): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProtectedResourceMetadata($resource, $authorizationServers, $scopes);
    }
}
