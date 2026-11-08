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

        yield 'plain http on a public host' => ['http://mcp.example.com/mcp', 'http://mcp.example.com/mcp', false];
        yield 'plain http on a loopback host' => ['http://localhost:8000/mcp', 'http://localhost:8000/mcp', true];

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

        yield 'a terminating slash on the root resource' => ['https://mcp.example.com/', 'https://mcp.example.com/mcp', true];
        yield 'a terminating slash on the resource, endpoint without it' => ['https://mcp.example.com/api/', 'https://mcp.example.com/api', true];
        yield 'a terminating slash on the resource, endpoint below it' => ['https://mcp.example.com/api/', 'https://mcp.example.com/api/x', true];
        yield 'a terminating slash on the resource, sharing a prefix' => ['https://mcp.example.com/api/', 'https://mcp.example.com/api-v2', false];
        yield 'an empty last segment, endpoint below it' => ['https://mcp.example.com/api//', 'https://mcp.example.com/api//x', true];
        yield 'an empty last segment does not cover the parent' => ['https://mcp.example.com/api//', 'https://mcp.example.com/api', false];
        yield 'an empty last segment does not cover a sibling' => ['https://mcp.example.com/api//', 'https://mcp.example.com/api/x', false];
        yield 'only slashes is not the root' => ['https://mcp.example.com//', 'https://mcp.example.com/mcp', false];

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidResourceProvider(): iterable
    {
        yield 'a fragment' => ['https://mcp.example.com/api#tenant-a'];
        yield 'an empty fragment' => ['https://mcp.example.com/api#'];
        yield 'userinfo hiding the host behind a backslash' => ['https://victim.example\@evil.example/mcp'];
        yield 'an empty user' => ['https://@evil.example/mcp'];
        yield 'only a password' => ['https://:x@evil.example/mcp'];
        yield 'a user and password' => ['https://a:b@mcp.example.com/mcp'];
        yield 'plain http on a public host' => ['http://mcp.example.com/mcp'];
        yield 'no scheme' => ['mcp.example.com'];
        yield 'only a path' => ['/mcp'];
        yield 'not a URL with a host' => ['urn:x:y'];
    }

    #[DataProvider('invalidResourceProvider')]
    #[TestDox('a document declaring an invalid resource identifier is rejected: $_dataName')]
    public function testRejectsAnInvalidResource(string $resource): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProtectedResourceMetadata::fromArray(['resource' => $resource]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validResourceProvider(): iterable
    {
        yield 'a loopback host over http' => ['http://localhost:8000/mcp'];
        yield 'IPv6 loopback over http' => ['http://[::1]:8000/mcp'];
        yield 'a query' => ['https://mcp.example.com/mcp?tenant=a'];
        yield 'an at sign in the path' => ['https://mcp.example.com/@tenant/mcp'];
        yield 'an uppercase scheme and host' => ['HTTPS://MCP.example.com/mcp'];
    }

    #[DataProvider('validResourceProvider')]
    #[TestDox('a document may declare the resource identifier: $_dataName')]
    public function testAcceptsAValidResource(string $resource): void
    {
        $this->assertSame($resource, ProtectedResourceMetadata::fromArray(['resource' => $resource])->resource);
    }
}
