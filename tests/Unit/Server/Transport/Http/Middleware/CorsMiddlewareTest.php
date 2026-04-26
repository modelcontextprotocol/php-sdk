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

use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddlewareTest extends TestCase
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

    #[TestDox('delegates to handler and adds CORS headers')]
    public function testDelegatesAndAddsCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[TestDox('default configuration does not set Access-Control-Allow-Origin')]
    public function testDefaultDoesNotSetAllowOrigin(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Methods'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Headers'));
        $this->assertTrue($response->hasHeader('Access-Control-Expose-Headers'));
    }

    #[TestDox('wildcard origin sets Access-Control-Allow-Origin to *')]
    public function testWildcardOrigin(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('matching origin is reflected in Access-Control-Allow-Origin')]
    public function testMatchingOriginIsReflected(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://myapp.com', 'https://staging.myapp.com'],
        );
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://myapp.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame('https://myapp.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('non-matching origin does not set Access-Control-Allow-Origin')]
    public function testNonMatchingOriginIsNotSet(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://myapp.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('pre-existing CORS headers on response are not overwritten')]
    public function testPreExistingHeadersNotOverwritten(): void
    {
        $handler = new class($this->factory) implements RequestHandlerInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withHeader('Access-Control-Allow-Origin', 'https://custom.com');
            }
        };

        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $handler);

        $this->assertSame('https://custom.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('custom allowed methods and headers are applied')]
    public function testCustomMethodsAndHeaders(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['*'],
            allowedMethods: ['POST'],
            allowedHeaders: ['Content-Type'],
            exposedHeaders: [],
        );
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $this->handler);

        $this->assertSame('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertFalse($response->hasHeader('Access-Control-Expose-Headers'));
    }
}
