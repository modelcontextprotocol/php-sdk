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

final class DnsRebindingProtectionMiddlewareTest extends MiddlewareTestCase
{
    public static function allowedOriginProvider(): iterable
    {
        yield 'localhost' => ['http://localhost:8000'];
        yield 'IPv4 loopback' => ['http://127.0.0.1:3000'];
        yield 'IPv6 loopback (bracketed)' => ['http://[::1]:8000'];
    }

    #[DataProvider('allowedOriginProvider')]
    #[TestDox('allows request with localhost Origin variant: $origin')]
    public function testAllowsLocalhostOrigin(string $origin): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
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

    #[TestDox('Origin header takes precedence over Host')]
    public function testOriginPrecedenceOverHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://localhost:8000')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('validates Host header when Origin is absent')]
    public function testFallbackToHostValidation(): void
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
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://myapp.local/')
            ->withHeader('Origin', 'http://myapp.local:3000');

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
            ->withHeader('Origin', 'http://MYAPP.LOCAL:80');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('request without Origin or Host is allowed')]
    public function testNoOriginNoHostPasses(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')->withoutHeader('Host');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }
}
