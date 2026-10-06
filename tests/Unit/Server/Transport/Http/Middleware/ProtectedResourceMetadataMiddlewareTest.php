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

use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtectedResourceMetadataMiddlewareTest extends MiddlewareTestCase
{
    public function testServesMetadataAtPathDerivedFromResource(): void
    {
        $response = $this->middleware()->process(
            $this->factory->createServerRequest('GET', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp'),
            $this->handlerReturning(404),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('https://mcp.example.com/mcp', $payload['resource']);
        $this->assertSame(['https://auth.example.com'], $payload['authorization_servers']);
    }

    public function testResourceQueryDecidesWhichMetadataIsServed(): void
    {
        $middleware = new ProtectedResourceMetadataMiddleware(
            new ProtectedResourceMetadata('https://mcp.example.com/mcp?tenant=a', ['https://auth.example.com']),
            $this->factory,
            $this->factory,
        );

        $served = $middleware->process($this->factory->createServerRequest('GET', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?tenant=a'), $this->handlerReturning(404));
        $other = $middleware->process($this->factory->createServerRequest('GET', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?tenant=b'), $this->handlerReturning(404));

        $this->assertSame(200, $served->getStatusCode());
        $this->assertSame(404, $other->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOtherRequests(): iterable
    {
        yield 'MCP endpoint' => ['GET', 'https://mcp.example.com/mcp'];
        yield 'root metadata path' => ['GET', 'https://mcp.example.com/.well-known/oauth-protected-resource'];
        yield 'POST to metadata path' => ['POST', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp'];
    }

    #[DataProvider('provideOtherRequests')]
    public function testOtherRequestsPassThrough(string $method, string $uri): void
    {
        $response = $this->middleware()->process($this->factory->createServerRequest($method, $uri), $this->handlerReturning(204));

        $this->assertSame(204, $response->getStatusCode());
    }

    private function middleware(): ProtectedResourceMetadataMiddleware
    {
        return new ProtectedResourceMetadataMiddleware(
            new ProtectedResourceMetadata('https://mcp.example.com/mcp', ['https://auth.example.com']),
            $this->factory,
            $this->factory,
        );
    }
}
