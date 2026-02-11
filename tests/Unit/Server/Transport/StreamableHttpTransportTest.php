<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StreamableHttpTransportTest extends TestCase
{
    public static function corsHeaderProvider(): iterable
    {
        yield 'GET (middleware returns 401)' => ['GET', false, 401];
        yield 'POST (middleware returns 401)' => ['POST', false, 401];
        yield 'DELETE (middleware returns 401)' => ['DELETE', false, 401];
        yield 'OPTIONS (middleware delegates -> transport handles preflight)' => ['OPTIONS', true, 204];
        yield 'GET (middleware delegates -> transport handles preflight)' => ['GET', true, 405];
        yield 'POST (middleware delegates -> transport handles preflight)' => ['POST', true, 202];
        yield 'DELETE (middleware delegates -> transport handles preflight)' => ['DELETE', true, 400];
    }

    #[DataProvider('corsHeaderProvider')]
    #[TestDox('CORS headers are always applied')]
    public function testCorsHeader(string $method, bool $middlewareDelegatesToTransport, int $expectedStatusCode): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'https://example.com');

        $middleware = new class($factory, $expectedStatusCode, $middlewareDelegatesToTransport) implements MiddlewareInterface {
            public function __construct(
                private ResponseFactoryInterface $responseFactory,
                private int $expectedStatusCode,
                private bool $middlewareDelegatesToTransport,
            ) {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if ($this->middlewareDelegatesToTransport) {
                    return $handler->handle($request);
                }

                return $this->responseFactory->createResponse($this->expectedStatusCode);
            }
        };

        $transport = new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            [],
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertSame($expectedStatusCode, $response->getStatusCode(), $response->getBody()->getContents());
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Methods'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Headers'));
        $this->assertTrue($response->hasHeader('Access-Control-Expose-Headers'));

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame(
            'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
            $response->getHeaderLine('Access-Control-Allow-Headers')
        );
        $this->assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('transport replaces existing CORS headers on the response')]
    public function testCorsHeadersAreReplacedWhenAlreadyPresent(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'https://example.com');

        $middleware = new class($factory) implements MiddlewareInterface {
            public function __construct(private ResponseFactoryInterface $responses)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->responses->createResponse(200)
                    ->withHeader('Access-Control-Allow-Origin', 'https://another.com');
            }
        };

        $transport = new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            [],
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('https://another.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame(
            'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
            $response->getHeaderLine('Access-Control-Allow-Headers')
        );
        $this->assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('middleware runs before transport handles the request')]
    public function testMiddlewareRunsBeforeTransportHandlesRequest(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('OPTIONS', 'https://example.com');

        $state = new \stdClass();
        $state->called = false;
        $middleware = new class($state) implements MiddlewareInterface {
            public function __construct(private \stdClass $state)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->state->called = true;

                return $handler->handle($request);
            }
        };

        $transport = new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            [],
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertTrue($state->called);
        $this->assertSame(204, $response->getStatusCode());
    }
}
