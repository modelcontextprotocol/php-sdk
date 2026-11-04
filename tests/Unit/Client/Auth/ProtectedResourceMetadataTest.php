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

use Mcp\Client\Auth\ProtectedResourceMetadata;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ProtectedResourceMetadataTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function coverageProvider(): iterable
    {
        yield 'the same URL' => ['https://mcp.example.com/mcp', 'https://mcp.example.com/mcp', true];
        yield 'a resource at the host root' => ['https://mcp.example.com', 'https://mcp.example.com/mcp', true];
        yield 'an endpoint below the resource' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/mcp', true];
        yield 'a sibling path' => ['https://mcp.example.com/other', 'https://mcp.example.com/mcp', false];
        yield 'a path that merely shares a prefix' => ['https://mcp.example.com/api', 'https://mcp.example.com/api-v2/mcp', false];
        yield 'a different host' => ['https://evil.example.com/mcp', 'https://mcp.example.com/mcp', false];
        yield 'a different scheme' => ['http://mcp.example.com/mcp', 'https://mcp.example.com/mcp', false];
        yield 'a different port' => ['http://localhost:9000/mcp', 'http://localhost:8000/mcp', false];

        yield 'the same query' => ['https://mcp.example.com/mcp?tenant=a', 'https://mcp.example.com/mcp?tenant=a', true];
        yield 'the same query below the resource' => ['https://mcp.example.com/mcp?tenant=a', 'https://mcp.example.com/mcp/sub?tenant=a', true];
        yield 'an endpoint query on a resource without one' => ['https://mcp.example.com/mcp', 'https://mcp.example.com/mcp?tenant=a', true];
        yield 'a different query' => ['https://mcp.example.com/mcp?tenant=a', 'https://mcp.example.com/mcp?tenant=b', false];
        yield 'a missing query' => ['https://mcp.example.com/mcp?tenant=a', 'https://mcp.example.com/mcp', false];

        yield 'a dot segment leaving the resource' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/../other', false];
        yield 'a nested dot segment leaving the resource' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/x/../../other', false];
        yield 'an encoded dot segment' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/%2e%2e/other', false];
        yield 'a half encoded dot segment' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/.%2e/other', false];
        yield 'a dot segment with an encoded slash' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/..%2fother', false];
        yield 'a single dot segment' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/./mcp', false];
        yield 'a dot segment in the resource' => ['https://mcp.example.com/api/..', 'https://mcp.example.com/api/../mcp', false];
        yield 'dots that are no dot segment' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/a..b/...', true];
        yield 'dots in the query' => ['https://mcp.example.com/api', 'https://mcp.example.com/api/x?y=/../z', true];

        yield 'an explicit default https port on the endpoint' => ['https://mcp.example.com/api', 'https://mcp.example.com:443/api', true];
        yield 'an explicit default https port on the resource' => ['https://mcp.example.com:443/api', 'https://mcp.example.com/api', true];
        yield 'an explicit default http port' => ['http://localhost/api', 'http://localhost:80/api', true];
        yield 'an uppercase host' => ['https://mcp.example.com/api', 'https://MCP.example.com/api', true];
        yield 'an uppercase scheme' => ['https://mcp.example.com/api', 'HTTPS://mcp.example.com/api', true];
        yield 'the https port with the http scheme' => ['https://mcp.example.com/api', 'http://mcp.example.com:443/api', false];
        yield 'the http port with the https scheme' => ['https://mcp.example.com/api', 'https://mcp.example.com:80/api', false];
        yield 'a non-default port' => ['https://mcp.example.com/api', 'https://mcp.example.com:8443/api', false];
        yield 'a path differing in case' => ['https://mcp.example.com/api', 'https://mcp.example.com/API', false];
    }

    #[DataProvider('coverageProvider')]
    #[TestDox('a token is allowed at the endpoint for: $_dataName')]
    public function testCovers(string $resource, string $endpoint, bool $expected): void
    {
        $this->assertSame($expected, (new ProtectedResourceMetadata($resource))->covers($endpoint));
    }

    #[TestDox('scopes_supported is kept apart from an absent list')]
    public function testDistinguishesEmptyAndAbsentScopes(): void
    {
        $this->assertNull(ProtectedResourceMetadata::fromArray(['resource' => 'https://mcp.example.com'])->scopesSupported);
        $this->assertSame([], ProtectedResourceMetadata::fromArray(['resource' => 'https://mcp.example.com', 'scopes_supported' => []])->scopesSupported);
    }

    #[TestDox('a document without a resource is rejected')]
    public function testRequiresResource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProtectedResourceMetadata::fromArray(['authorization_servers' => ['https://auth.example.com']]);
    }
}
