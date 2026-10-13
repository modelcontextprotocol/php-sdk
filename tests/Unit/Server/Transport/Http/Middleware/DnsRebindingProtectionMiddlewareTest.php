<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\Middleware;

use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Server\RequestHandlerInterface;

final class DnsRebindingProtectionMiddlewareTest extends MiddlewareTestCase
{
    public static function allowedOriginProvider(): iterable
    {
        yield 'localhost' => ['http://localhost:8000'];
        yield 'IPv4 loopback' => ['http://127.0.0.1:3000'];
        yield 'IPv6 loopback (bracketed)' => ['http://[::1]:8000'];
    }

    #[DataProvider('allowedOriginProvider')]
    #[TestDox('allows an explicitly configured Origin: $origin')]
    public function testAllowsConfiguredOrigin(string $origin): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(allowedOrigins: [$origin], responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', $origin);

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('rejects non-allowed Origin with 403')]
    public function testRejectsForeignOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Origin', (string) $response->getBody());
    }

    #[TestDox('an allowed Origin cannot bypass Host validation')]
    public function testAllowedOriginCannotBypassHostValidation(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(allowedOrigins: ['http://localhost:8000'], responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://localhost:8000')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Invalid Host header.', (string) $response->getBody());
    }

    #[TestDox('validates Host header when Origin is absent')]
    public function testHostValidationWithoutOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil/')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('strips port from Host header when validating')]
    public function testHostPortIsStripped(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost:8000');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('IPv6 Host with port is parsed correctly')]
    public function testIpv6HostWithPort(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', '[::1]:8080');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('custom allowed hosts permit non-localhost names')]
    public function testCustomAllowedHosts(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedHosts: ['myapp.local'],
            allowedOrigins: ['https://client.example:3000'],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://myapp.local/')
            ->withHeader('Origin', 'https://client.example:3000');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('host comparison is case-insensitive')]
    public function testCaseInsensitive(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedHosts: ['MyApp.Local'],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://myapp.local/')
            ->withHeader('Host', 'MYAPP.LOCAL:80');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('request without Host is rejected')]
    public function testMissingHostIsRejected(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')->withoutHeader('Host');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
    }

    public static function rejectedOriginProvider(): iterable
    {
        yield 'different scheme' => ['http://client.example:8443'];
        yield 'different port' => ['https://client.example:443'];
        yield 'omitted port' => ['https://client.example'];
        yield 'different host' => ['https://other.example:8443'];
        yield 'host suffix' => ['https://client.example.evil:8443'];
        yield 'path' => ['https://client.example:8443/'];
        yield 'userinfo' => ['https://user@client.example:8443'];
        yield 'opaque origin' => ['null'];
        yield 'empty origin' => [''];
        yield 'malformed origin' => ['not a URL'];
        yield 'multiple origins' => [['https://client.example:8443', 'https://evil.example']];
    }

    #[DataProvider('rejectedOriginProvider')]
    #[TestDox('rejects an Origin not exactly in the allowlist: $_dataName')]
    public function testRejectsUnlistedOrigin(string|array $origin): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedOrigins: ['https://client.example:8443'],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', $origin);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Invalid Origin header.', (string) $response->getBody());
    }

    public static function nonCanonicalAllowedOriginProvider(): iterable
    {
        yield 'uppercase' => ['https://Client.example', 'https://client.example'];
        yield 'trailing slash' => ['https://client.example/', 'https://client.example'];
        yield 'explicit default port' => ['https://client.example:443', 'https://client.example'];
        yield 'explicit HTTP default port' => ['HTTP://client.example:80/', 'http://client.example'];
        yield 'custom port kept' => ['http://client.example:8080', 'http://client.example:8080'];
    }

    #[DataProvider('nonCanonicalAllowedOriginProvider')]
    #[TestDox('allowed origins are normalized like browsers send them: $_dataName')]
    public function testNonCanonicalAllowedOriginMatches(string $allowedOrigin, string $origin): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedOrigins: [$allowedOrigin],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', $origin);

        $this->assertSame(200, $middleware->process($request, $this->passthroughHandler)->getStatusCode());
    }

    public static function wildcardOriginProvider(): iterable
    {
        yield 'cross origin' => ['https://client.example'];
        yield 'different scheme and port' => ['https://localhost:8443'];
        yield 'opaque origin' => ['null'];
        yield 'empty origin' => [''];
        yield 'missing origin' => [null];
    }

    #[DataProvider('wildcardOriginProvider')]
    #[TestDox('wildcard origins still validate Host: $_dataName')]
    public function testWildcardOriginsStillValidateHosts(?string $origin): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedOrigins: ['*'],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');
        if (null !== $origin) {
            $request = $request->withHeader('Origin', $origin);
        }

        $this->assertSame(200, $middleware->process($request, $this->passthroughHandler)->getStatusCode());

        foreach ([$request->withHeader('Host', 'evil.example'), $request->withoutHeader('Host')] as $invalidRequest) {
            $response = $middleware->process($invalidRequest, $this->passthroughHandler);

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('Forbidden: Invalid Host header.', (string) $response->getBody());
        }
    }

    public static function wildcardHostProvider(): iterable
    {
        yield 'hostname' => ['https://server.example/mcp', null, 200];
        yield 'custom port' => ['https://server.example:8443/mcp', null, 200];
        yield 'IPv4' => ['http://192.0.2.1/mcp', null, 200];
        yield 'IPv6' => ['http://[2001:db8::1]:8000/mcp', null, 200];
        yield 'same origin' => ['https://server.example/mcp', 'https://server.example', 200];
        yield 'allowed cross origin' => ['https://server.example/mcp', 'https://client.example', 200];
        yield 'unlisted cross origin' => ['https://server.example/mcp', 'https://other.example', 403];
    }

    #[DataProvider('wildcardHostProvider')]
    #[TestDox('wildcard hosts still validate Origin: $_dataName')]
    public function testWildcardHosts(string $uri, ?string $origin, int $status): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedHosts: ['*'],
            allowedOrigins: ['https://client.example'],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', $uri);
        if (null !== $origin) {
            $request = $request->withHeader('Origin', $origin);
        }

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame($status, $response->getStatusCode());
        if (403 === $status) {
            $this->assertSame('Forbidden: Invalid Origin header.', (string) $response->getBody());
        }
    }

    #[TestDox('wildcard hosts trust Host for the same-origin check, so they do not stop DNS rebinding alone')]
    public function testWildcardHostsAcceptRebindingRequest(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(allowedHosts: ['*'], responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example:8000/mcp')
            ->withHeader('Origin', 'http://evil.example:8000');

        $this->assertSame(200, $middleware->process($request, $this->passthroughHandler)->getStatusCode());
    }

    #[TestDox('wildcard hosts still require a Host header')]
    public function testWildcardHostsStillRequireHostHeader(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(allowedHosts: ['*'], responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')->withoutHeader('Host');

        $this->assertSame(403, $middleware->process($request, $this->passthroughHandler)->getStatusCode());
    }

    #[TestDox('empty allowed hosts reject every Host')]
    public function testEmptyAllowedHostsRejectsAllHosts(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(allowedHosts: [], responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://localhost');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Invalid Host header.', (string) $response->getBody());
    }

    public static function sameOriginProvider(): iterable
    {
        yield 'HTTP' => ['http://localhost/mcp?foo=bar', 'http://localhost', 200];
        yield 'HTTPS' => ['https://localhost/mcp', 'https://localhost', 200];
        yield 'explicit HTTP default port' => ['http://localhost/mcp', 'http://localhost:80', 200];
        yield 'explicit HTTPS default port' => ['https://localhost/mcp', 'https://localhost:443', 200];
        yield 'request default port' => ['https://localhost:443/mcp', 'https://localhost', 200];
        yield 'custom port' => ['https://localhost:8443/mcp', 'https://localhost:8443', 200];
        yield 'case insensitive' => ['https://localhost/mcp', 'HTTPS://LOCALHOST', 200];
        yield 'IPv4' => ['http://127.0.0.1/mcp', 'http://127.0.0.1', 200];
        yield 'IPv6' => ['http://[::1]:8000/mcp', 'http://[::1]:8000', 200];
        yield 'different scheme' => ['https://localhost/mcp', 'http://localhost', 403];
        yield 'different port' => ['https://localhost:8443/mcp', 'https://localhost', 403];
        yield 'different default port' => ['https://localhost/mcp', 'https://localhost:80', 403];
        yield 'different allowed host' => ['http://localhost/mcp', 'http://127.0.0.1', 403];
        yield 'path' => ['http://localhost/mcp', 'http://localhost/', 403];
        yield 'query' => ['http://localhost/mcp', 'http://localhost?foo', 403];
        yield 'fragment' => ['http://localhost/mcp', 'http://localhost#foo', 403];
        yield 'userinfo' => ['http://localhost/mcp', 'http://user@localhost', 403];
        yield 'opaque origin' => ['http://localhost/mcp', 'null', 403];
        yield 'empty origin' => ['http://localhost/mcp', '', 403];
        yield 'invalid port' => ['http://localhost/mcp', 'http://localhost:99999', 403];
        yield 'missing scheme' => ['http://localhost/mcp', '//localhost', 403];
        yield 'multiple origins' => ['http://localhost/mcp', 'http://localhost, http://evil.example', 403];
    }

    #[DataProvider('sameOriginProvider')]
    #[TestDox('same-origin check: $_dataName')]
    public function testSameOrigin(string $uri, string $origin, int $status): void
    {
        foreach ([[], ['https://client.example']] as $allowedOrigins) {
            $middleware = new DnsRebindingProtectionMiddleware(
                allowedOrigins: $allowedOrigins,
                responseFactory: $this->factory,
                streamFactory: $this->factory,
            );
            $request = $this->factory->createServerRequest('POST', $uri)->withHeader('Origin', $origin);

            $this->assertSame($status, $middleware->process($request, $this->passthroughHandler)->getStatusCode());
        }
    }

    #[TestDox('a same-origin request cannot bypass Host validation')]
    public function testSameOriginCannotBypassHostValidation(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example/mcp')
            ->withHeader('Origin', 'http://evil.example');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Invalid Host header.', (string) $response->getBody());
    }
}
