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
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DnsRebindingProtectionMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->handler = new class($this->factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }

    #[TestDox('allows request with valid localhost Origin header')]
    public function testAllowsLocalhostOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://localhost:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with 127.0.0.1 Origin header')]
    public function testAllows127001Origin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://127.0.0.1:3000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with [::1] Origin header')]
    public function testAllowsIpv6LocalhostOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://[::1]:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with no Origin header')]
    public function testAllowsEmptyOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('rejects request with evil Origin header')]
    public function testRejectsEvilOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Origin', (string) $response->getBody());
    }

    #[TestDox('rejects request with evil Origin header even with port')]
    public function testRejectsEvilOriginWithPort(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://evil.example.com:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('rejects malformed Origin header')]
    public function testRejectsMalformedOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'not-a-url');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('Origin matching is case-insensitive')]
    public function testOriginMatchingIsCaseInsensitive(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://LOCALHOST:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('supports custom allowed hosts')]
    public function testCustomAllowedHosts(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedHosts: ['myapp.local'],
            responseFactory: $this->factory,
        );

        $allowed = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://myapp.local:9000');
        $this->assertSame(200, $middleware->process($allowed, $this->handler)->getStatusCode());

        $rejected = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Origin', 'http://localhost');
        $this->assertSame(403, $middleware->process($rejected, $this->handler)->getStatusCode());
    }

    #[TestDox('rejects request with evil Host header when no Origin is present')]
    public function testRejectsEvilHostWithoutOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com/')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Host', (string) $response->getBody());
    }

    #[TestDox('rejects request with evil Host header including port when no Origin is present')]
    public function testRejectsEvilHostWithPortWithoutOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com:8000/')
            ->withHeader('Host', 'evil.example.com:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('allows request with localhost Host header when no Origin is present')]
    public function testAllowsLocalhostHostWithoutOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/')
            ->withHeader('Host', 'localhost:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with IPv6 Host header when no Origin is present')]
    public function testAllowsIpv6HostWithoutOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://[::1]:8000/')
            ->withHeader('Host', '[::1]:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('Origin takes precedence over Host header')]
    public function testOriginTakesPrecedenceOverHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        // Valid Origin but evil Host — should pass because Origin is checked first
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com/')
            ->withHeader('Origin', 'http://localhost:8000')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allowed hosts are normalized to lowercase')]
    public function testAllowedHostsAreCaseInsensitive(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(
            allowedHosts: ['MyApp.Local'],
            responseFactory: $this->factory,
        );

        $request = $this->factory->createServerRequest('POST', 'http://myapp.local/')
            ->withHeader('Origin', 'http://myapp.local:9000');

        $this->assertSame(200, $middleware->process($request, $this->handler)->getStatusCode());
    }

    #[TestDox('Host matching is case-insensitive')]
    public function testHostMatchingIsCaseInsensitive(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://LOCALHOST:8000/')
            ->withHeader('Host', 'LOCALHOST:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }
}
