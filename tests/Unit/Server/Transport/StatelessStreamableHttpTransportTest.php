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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StatelessStreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class StatelessStreamableHttpTransportTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[TestDox('default middleware is applied when none is passed')]
    public function testDefaultMiddlewareIsAppliedWhenOmitted(): void
    {
        $request = $this->factory
            ->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertNotSame('', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('POST without session ID is accepted')]
    public function testPostWithoutSessionIdIsAccepted(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withBody($this->factory->createStream('{"jsonrpc":"2.0","method":"server/discover","id":1}'));

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertNotSame(400, $response->getStatusCode());
        $this->assertNotSame(404, $response->getStatusCode());
    }

    #[TestDox('DELETE method returns 405')]
    public function testDeleteMethodReturns405(): void
    {
        $request = $this->factory
            ->createServerRequest('DELETE', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST, OPTIONS', $response->getHeaderLine('Allow'));
    }

    #[TestDox('GET method returns 405')]
    public function testGetMethodReturns405(): void
    {
        $request = $this->factory
            ->createServerRequest('GET', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST, OPTIONS', $response->getHeaderLine('Allow'));
    }

    #[TestDox('default middleware blocks non-localhost Origin')]
    public function testDefaultMiddlewareBlocksRebindingAttempt(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'http://evil.example.com');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('default middleware rejects unsupported MCP-Protocol-Version')]
    public function testDefaultMiddlewareRejectsUnsupportedProtocolVersion(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader(StatelessStreamableHttpTransport::PROTOCOL_VERSION_HEADER, '1900-01-01');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('explicit empty middleware list disables defaults and emits a warning log')]
    public function testEmptyMiddlewareListDisablesDefaultsAndWarns(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'evil.example.com')
            ->withHeader('Origin', 'http://evil.example.com');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('empty middleware list'));

        $transport = new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            $logger,
            [],
        );

        $response = $transport->listen();

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }

    #[TestDox('null middleware does not trigger the empty-list warning')]
    public function testNullMiddlewareDoesNotWarn(): void
    {
        $request = $this->factory
            ->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory, $logger);
        $transport->listen();
    }

    #[TestDox('custom middleware composes with default stack via spread')]
    public function testDefaultsCanBeSpreadAndExtended(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $transport = new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                ...StatelessStreamableHttpTransport::defaultMiddleware(),
                $this->stubAuth401(),
            ],
        );

        $response = $transport->listen();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('defaults can be filtered to drop DNS rebinding for proxy deployments')]
    public function testDefaultsCanBeFilteredToDropDnsRebinding(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://api.myapp.com/')
            ->withHeader('Host', 'api.myapp.com')
            ->withHeader('Origin', 'https://myapp.com');

        $transport = new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                ...array_filter(
                    StatelessStreamableHttpTransport::defaultMiddleware(),
                    static fn (MiddlewareInterface $m): bool => !$m instanceof DnsRebindingProtectionMiddleware,
                ),
                $this->stubAuth401(),
            ],
        );

        $response = $transport->listen();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('configured CorsMiddleware reflects matching Origin')]
    public function testConfiguredCorsReflectsMatchingOrigin(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'https://myapp.example.com');

        $transport = new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                new CorsMiddleware(allowedOrigins: ['https://myapp.example.com']),
                new DnsRebindingProtectionMiddleware(allowedHosts: ['localhost']),
                new ProtocolVersionMiddleware(),
            ],
        );

        $response = $transport->listen();

        $this->assertSame('https://myapp.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('middleware runs before transport handles the request')]
    public function testMiddlewareRunsBeforeTransportHandlesRequest(): void
    {
        $request = $this->factory->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $state = new \stdClass();
        $state->called = false;
        $spy = new class($state) implements MiddlewareInterface {
            public function __construct(private \stdClass $state)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->state->called = true;

                return $handler->handle($request);
            }
        };

        $transport = new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [$spy],
        );

        $response = $transport->listen();

        $this->assertTrue($state->called);
        $this->assertSame(204, $response->getStatusCode());
    }

    #[TestDox('non-middleware entries are rejected')]
    public function testInvalidMiddlewareEntryThrows(): void
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $this->expectException(InvalidArgumentException::class);

        new StatelessStreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [new \stdClass()], // @phpstan-ignore-line argument.type
        );
    }

    public function testPostBodyExceedingMaxBytesReturns413(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withBody($this->factory->createStream(str_repeat('a', 64)));

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 16);

        $response = $transport->listen();

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testPostBodyWithinMaxBytesIsNotRejected(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withBody($this->factory->createStream('{}'));

        $transport = new StatelessStreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 1024);

        $response = $transport->listen();

        $this->assertNotSame(413, $response->getStatusCode());
    }

    public function testNonPositiveMaxBodyBytesThrows(): void
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $this->expectException(InvalidArgumentException::class);

        new StatelessStreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 0);
    }

    private function stubAuth401(): MiddlewareInterface
    {
        return new class($this->factory) implements MiddlewareInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->factory->createResponse(401);
            }
        };
    }
}
