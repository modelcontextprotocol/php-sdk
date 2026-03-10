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

    #[TestDox('allows request with localhost Host header')]
    public function testAllowsLocalhostHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/')
            ->withHeader('Host', 'localhost:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with 127.0.0.1 Host header')]
    public function testAllows127001Host(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://127.0.0.1/')
            ->withHeader('Host', '127.0.0.1:3000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with [::1] Host header')]
    public function testAllowsIpv6LocalhostHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://[::1]/')
            ->withHeader('Host', '[::1]:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('allows request with no Host header')]
    public function testAllowsEmptyHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withoutHeader('Host');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('rejects request with evil Host header')]
    public function testRejectsEvilHost(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com/')
            ->withHeader('Host', 'evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Host', (string) $response->getBody());
    }

    #[TestDox('rejects request with evil Host header even with port')]
    public function testRejectsEvilHostWithPort(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com:8000/')
            ->withHeader('Host', 'evil.example.com:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('allows request with valid localhost Origin header')]
    public function testAllowsLocalhostOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost:8000')
            ->withHeader('Origin', 'http://localhost:8000');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('rejects request with evil Origin header')]
    public function testRejectsEvilOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost:8000')
            ->withHeader('Origin', 'http://evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Origin', (string) $response->getBody());
    }

    #[TestDox('rejects malformed Origin header')]
    public function testRejectsMalformedOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'not-a-url');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('Host matching is case-insensitive')]
    public function testHostMatchingIsCaseInsensitive(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'LOCALHOST:8000');

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

        $allowed = $this->factory->createServerRequest('POST', 'http://myapp.local/')
            ->withHeader('Host', 'myapp.local:9000');
        $this->assertSame(200, $middleware->process($allowed, $this->handler)->getStatusCode());

        $rejected = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost');
        $this->assertSame(403, $middleware->process($rejected, $this->handler)->getStatusCode());
    }

    #[TestDox('allows request with no Origin header')]
    public function testAllowsEmptyOrigin(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('Host header check runs before Origin header check')]
    public function testHostCheckRunsBeforeOriginCheck(): void
    {
        $middleware = new DnsRebindingProtectionMiddleware(responseFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com/')
            ->withHeader('Host', 'evil.example.com')
            ->withHeader('Origin', 'http://evil.example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Host', (string) $response->getBody());
    }
}
