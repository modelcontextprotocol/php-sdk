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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\ServerRequestInterface;

final class CorsMiddlewareTest extends MiddlewareTestCase
{
    #[TestDox('default configuration does not advertise an allowed origin')]
    public function testDefaultDoesNotSetAllowOrigin(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($response->hasHeader('Access-Control-Expose-Headers'));
        // Non-preflight: Methods/Headers must NOT be emitted per CORS spec.
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Headers'));
    }

    #[TestDox('preflight request receives Access-Control-Allow-Methods and Access-Control-Allow-Headers')]
    public function testPreflightReceivesMethodAndHeaderAdvertisements(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->preflightRequest('https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[TestDox('non-preflight OPTIONS request does not receive Methods/Headers advertisements')]
    public function testPlainOptionsIsNotTreatedAsPreflight(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        // OPTIONS without `Access-Control-Request-Method` is not a CORS preflight.
        $request = $this->factory->createServerRequest('OPTIONS', 'https://example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Headers'));
    }

    #[TestDox('wildcard allowedOrigins sets Access-Control-Allow-Origin to *')]
    public function testWildcardOrigin(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('matching Origin is reflected back')]
    public function testMatchingOriginIsReflected(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://app.example.com', 'https://staging.example.com'],
        );
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('non-matching Origin is not echoed')]
    public function testNonMatchingOriginIsBlocked(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('does not overwrite headers set by inner middleware')]
    public function testPreExistingHeadersAreNotOverwritten(): void
    {
        $inner = $this->handlerReturning(200, [
            'Access-Control-Allow-Origin' => 'https://override.example.com',
            'Access-Control-Allow-Methods' => 'POST',
        ]);

        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->preflightRequest();

        $response = $middleware->process($request, $inner);

        $this->assertSame('https://override.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[TestDox('exposed headers can be omitted')]
    public function testEmptyExposedHeadersAreNotSet(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*'], exposedHeaders: []);
        $request = $this->factory->createServerRequest('POST', 'https://example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Expose-Headers'));
    }

    #[TestDox('adds Vary: Origin when reflecting a specific origin to protect caches')]
    public function testVaryOriginIsAddedForReflectedOrigin(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('adds Vary: Origin even when origin is rejected so caches do not poison')]
    public function testVaryOriginIsAddedEvenWhenOriginDoesNotMatch(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('does not add Vary when Access-Control-Allow-Origin is wildcard')]
    public function testVaryOriginIsNotAddedForWildcard(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Vary'));
    }

    #[TestDox('does not add Vary when no allowed origins are configured')]
    public function testVaryOriginIsNotAddedWhenAllowedOriginsEmpty(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Vary'));
    }

    #[TestDox('preserves existing Vary value when appending Origin')]
    public function testVaryOriginAppendsToExistingVary(): void
    {
        $inner = $this->handlerReturning(200, ['Vary' => 'Accept-Encoding']);

        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $inner);

        $this->assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('does not duplicate Origin in existing Vary header')]
    public function testVaryOriginIsNotDuplicated(): void
    {
        $inner = $this->handlerReturning(200, ['Vary' => 'Accept-Encoding, Origin']);

        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $inner);

        $this->assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('does not treat a substring match like Origin-Other as the Origin token')]
    public function testVarySubstringDoesNotPreventAppending(): void
    {
        // `Origin-Resource-Policy` contains the substring "origin" but is a different token —
        // tokenized comparison must still treat the response as missing the `Origin` value.
        $inner = $this->handlerReturning(200, ['Vary' => 'Origin-Resource-Policy']);

        $middleware = new CorsMiddleware(allowedOrigins: ['https://app.example.com']);
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $inner);

        $this->assertSame('Origin-Resource-Policy, Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('allowCredentials emits Access-Control-Allow-Credentials when an origin matches')]
    public function testAllowCredentialsHeaderEmitted(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://app.example.com'],
            allowCredentials: true,
        );
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[TestDox('allowCredentials does not emit credentials header when no origin matches')]
    public function testAllowCredentialsSkippedWhenOriginUnmatched(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://app.example.com'],
            allowCredentials: true,
        );
        $request = $this->factory->createServerRequest('POST', 'https://example.com')
            ->withHeader('Origin', 'https://evil.example.com');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    #[TestDox('combining wildcard origin with allowCredentials throws')]
    public function testWildcardWithCredentialsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsMiddleware(allowedOrigins: ['*'], allowCredentials: true);
    }

    private function preflightRequest(string $origin = 'https://app.example.com'): ServerRequestInterface
    {
        return $this->factory
            ->createServerRequest('OPTIONS', 'https://example.com')
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'Content-Type');
    }
}
