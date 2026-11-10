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
        yield 'http on localhost without port' => ['http://localhost'];
        yield 'http on localhost with trailing dot' => ['http://LOCALHOST.'];
        yield 'http on localhost subdomain' => ['http://shop.localhost:8080'];
        yield 'http on nested localhost subdomain' => ['http://a.b.localhost'];
        yield 'http on uppercase localhost subdomain with trailing dot' => ['http://Shop.LocalHost.:8080/mcp'];
        yield 'http on other IPv4 loopback address' => ['http://127.1.2.3'];
        yield 'http on IPv6 loopback spelled out' => ['http://[0:0:0:0:0:0:0:1]/mcp'];
        yield 'https on shortened IPv6 loopback' => ['https://[0::1]/mcp'];
        yield 'https on public IPv4 address' => ['https://203.0.113.5/mcp'];
        yield 'https on IPv6 address' => ['https://[2001:db8::1]/mcp'];
        yield 'https on name of hex letters' => ['https://deadbeef.example.com'];
        yield 'https on numeric label' => ['https://123.example.com'];
        yield 'https on punycode name' => ['https://xn--bcher-kva.example'];
        yield 'percent-encoding outside the host' => ['https://auth.example.com/realms/%6dcp?q=%31'];
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
        yield 'http on localhost subdomain lookalike' => ['http://shop.localhost.example.com'];
        yield 'http on host ending in localhost' => ['http://notlocalhost'];
        yield 'http on host starting with localhost' => ['http://localhostx'];
        yield 'http on empty localhost label' => ['http://.localhost'];
        yield 'http on private IPv4 address' => ['http://10.0.0.1'];
        yield 'http on IPv4 lookalike' => ['http://127.0.0.1.example.com'];
        yield 'http on IPv4-mapped IPv6 address' => ['http://[::ffff:127.0.0.1]'];
        yield 'other scheme' => ['ftp://auth.example.com'];
        yield 'other scheme on localhost' => ['ftp://localhost/jwks.json'];
        yield 'file scheme on localhost' => ['file://localhost/etc/passwd'];
        yield 'other scheme on IPv4 loopback' => ['gopher://127.0.0.1/'];
        yield 'other scheme on IPv6 loopback' => ['javascript://[::1]/'];
        yield 'relative' => ['/realms/mcp'];
        yield 'no host' => ['https:///realms/mcp'];
        yield 'not a string' => [null];
        yield 'backslash before userinfo on http' => ['http://evil.example\@localhost/x'];
        yield 'backslash before userinfo on https' => ['https://evil.example\@auth.example.com/x'];
        yield 'backslash in the path' => ['https://auth.example.com\realms/mcp'];
        yield 'tab in the host' => ["http://evil.example\t.localhost"];
        yield 'newline in the host' => ["https://auth.example\n.com"];
        yield 'trailing newline' => ["https://auth.example.com\n"];
        yield 'NUL in the host' => ["http://evil.example\0.localhost"];
        yield 'space in the host' => ['https://auth.example .com'];
        yield 'DEL in the host' => ["http://evil.example\x7f.localhost"];
        yield 'unspecified IPv4 address' => ['https://0.0.0.0/x'];
        yield 'unspecified IPv6 address' => ['https://[::]/x'];
        yield 'IPv4-mapped loopback on https' => ['https://[::ffff:127.0.0.1]/x'];
        yield 'IPv4-mapped private address' => ['https://[::ffff:10.0.0.1]/x'];
        yield 'IPv4-compatible loopback' => ['https://[::127.0.0.1]/x'];
        yield 'shortened IPv4 loopback' => ['https://127.1/x'];
        yield 'decimal IPv4 loopback' => ['https://2130706433/x'];
        yield 'hexadecimal IPv4 loopback' => ['https://0x7f000001/x'];
        yield 'octal IPv4 loopback' => ['https://0177.0.0.1/x'];
        yield 'zero-padded IPv4 loopback' => ['https://127.000.000.001/x'];
        yield 'shortened IPv4 with trailing dot' => ['https://127.1./x'];
        yield 'percent-encoded IPv4 loopback' => ['https://%31%32%37.0.0.1/x'];
        yield 'percent-encoded shortened IPv4 loopback' => ['https://%31%32%37.1/x'];
        yield 'percent-encoded name' => ['https://%61uth.example.com/x'];
        yield 'fullwidth digits' => ['https://１２７.0.0.1/x'];
        yield 'ideographic full stops' => ['https://127。0。0。1/x'];
        yield 'name that is not punycode' => ['https://bücher.example/x'];
    }

    #[DataProvider('provideRejectedUrls')]
    public function testRejectsInsecureOrRelativeUrls(mixed $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        SecureUrl::parse($url, 'issuer');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideLoopbackUrls(): iterable
    {
        yield 'localhost' => ['https://localhost/x', true];
        yield 'IPv4 loopback' => ['https://127.0.0.1/x', true];
        yield 'IPv6 loopback' => ['https://[::1]/x', true];
        yield 'IPv6 loopback spelled out' => ['https://[0:0:0:0:0:0:0:1]/x', true];
        yield 'shortened IPv6 loopback' => ['https://[0::1]/x', true];
        yield 'public name' => ['https://auth.example.com/x', false];
        yield 'other IPv6 address' => ['https://[::2]/x', false];
    }

    #[DataProvider('provideLoopbackUrls')]
    public function testDetectsLoopbackUrls(string $url, bool $loopback): void
    {
        $this->assertSame($loopback, SecureUrl::isLoopback($url));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providePrivateNetworkUrls(): iterable
    {
        yield '10/8' => ['https://10.0.0.5/x', true];
        yield 'upper end of 172.16/12' => ['https://172.31.255.1/x', true];
        yield 'above 172.16/12' => ['https://172.32.0.1/x', false];
        yield '192.168/16' => ['https://192.168.1.1/x', true];
        yield 'link-local metadata service' => ['https://169.254.169.254/x', true];
        yield 'shared address space' => ['https://100.100.100.200/x', true];
        yield 'above the shared address space' => ['https://100.128.0.1/x', false];
        yield 'unique local IPv6' => ['https://[fd00::1]/x', true];
        yield 'link-local IPv6' => ['https://[fe80::1]/x', true];
        yield 'public IPv4' => ['https://8.8.8.8/x', false];
        yield 'public IPv6' => ['https://[2001:db8::1]/x', false];
        yield 'loopback' => ['https://127.0.0.1/x', false];
        yield 'a name, which is not resolved' => ['https://internal.corp/x', false];
    }

    #[DataProvider('providePrivateNetworkUrls')]
    public function testDetectsPrivateNetworkUrls(string $url, bool $private): void
    {
        $this->assertSame($private, SecureUrl::isPrivateNetwork($url));
    }
}
