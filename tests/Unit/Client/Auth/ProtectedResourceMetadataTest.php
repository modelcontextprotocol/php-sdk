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
