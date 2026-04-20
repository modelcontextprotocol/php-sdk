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

use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
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
        yield 'POST (middleware returns 401)' => ['POST', false, 401];
        yield 'DELETE (middleware returns 401)' => ['DELETE', false, 401];
        yield 'OPTIONS (middleware delegates -> transport handles preflight)' => ['OPTIONS', true, 204];
        yield 'GET (middleware delegates -> transport returns 405)' => ['GET', true, 405];
        yield 'POST (middleware delegates -> transport returns 202)' => ['POST', true, 202];
        yield 'DELETE (middleware delegates -> transport returns 400)' => ['DELETE', true, 400];
    }

    #[DataProvider('corsHeaderProvider')]
    #[TestDox('CORS headers are applied by default CorsMiddleware')]
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
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertSame($expectedStatusCode, $response->getStatusCode(), $response->getBody()->getContents());
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Methods'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Headers'));
        $this->assertTrue($response->hasHeader('Access-Control-Expose-Headers'));
        // Default CorsMiddleware has no allowed origins, so no Access-Control-Allow-Origin
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('CORS headers include Access-Control-Allow-Origin when CorsMiddleware is configured with origins')]
    public function testCorsHeaderWithConfiguredOrigins(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.com');

        $transport = new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            null,
            [new CorsMiddleware(allowedOrigins: ['*'])],
        );

        $response = $transport->listen();

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[TestDox('middleware can set CORS headers that CorsMiddleware will not overwrite')]
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
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('https://another.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[TestDox('middleware runs before transport handles the request')]
    public function testMiddlewareRunsBeforeTransportHandlesRequest(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.com');

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
            null,
            [$middleware],
        );

        $response = $transport->listen();

        $this->assertTrue($state->called);
        $this->assertSame(202, $response->getStatusCode());
    }
}
