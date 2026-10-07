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
use Mcp\Server\Transport\Http\OAuth\SecureUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecureUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedUrls(): iterable
    {
        yield 'https' => ['https://auth.example.com/realms/mcp'];
        yield 'uppercase scheme' => ['HTTPS://auth.example.com'];
        yield 'http on localhost' => ['http://localhost:8080'];
        yield 'http on IPv4 loopback' => ['http://127.0.0.1/mcp'];
        yield 'http on IPv6 loopback' => ['http://[::1]:8000/mcp'];
        yield 'http on uppercase localhost' => ['http://LOCALHOST'];
        yield 'uppercase http on localhost' => ['HTTP://localhost'];
    }

    #[DataProvider('provideAcceptedUrls')]
    public function testAcceptsSecureAndLoopbackUrls(string $url): void
    {
        $this->assertArrayHasKey('host', SecureUrl::parse($url, 'issuer'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRejectedUrls(): iterable
    {
        yield 'http' => ['http://auth.example.com'];
        yield 'http on loopback lookalike' => ['http://localhost.example.com'];
        yield 'http on other loopback address' => ['http://127.0.0.2'];
        yield 'other scheme' => ['ftp://auth.example.com'];
        yield 'other scheme on localhost' => ['ftp://localhost/jwks.json'];
        yield 'file scheme on localhost' => ['file://localhost/etc/passwd'];
        yield 'other scheme on IPv4 loopback' => ['gopher://127.0.0.1/'];
        yield 'other scheme on IPv6 loopback' => ['javascript://[::1]/'];
        yield 'relative' => ['/realms/mcp'];
        yield 'no host' => ['https:///realms/mcp'];
        yield 'not a string' => [null];
    }

    #[DataProvider('provideRejectedUrls')]
    public function testRejectsInsecureOrRelativeUrls(mixed $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        SecureUrl::parse($url, 'issuer');
    }
}
