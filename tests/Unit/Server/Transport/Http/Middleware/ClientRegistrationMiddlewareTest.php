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

use Mcp\Exception\ClientRegistrationException;
use Mcp\Server\Transport\Http\Middleware\ClientRegistrationMiddleware;
use Mcp\Server\Transport\Http\OAuth\ClientRegistrarInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ClientRegistrationMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[TestDox('POST /register with valid JSON delegates to registrar and returns 201')]
    public function testRegistrationSuccess(): void
    {
        $registrar = $this->createMock(ClientRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('register')
            ->with(['redirect_uris' => ['https://example.com/callback']])
            ->willReturn(['client_id' => 'new-client', 'client_secret' => 's3cret']);

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withBody($this->factory->createStream(json_encode(['redirect_uris' => ['https://example.com/callback']])));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('new-client', $payload['client_id']);
        $this->assertSame('s3cret', $payload['client_secret']);
    }

    #[TestDox('POST /register with invalid JSON returns 400')]
    public function testRegistrationWithInvalidJson(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withBody($this->factory->createStream('not json'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('Request body must be valid JSON.', $payload['error_description']);
    }

    #[TestDox('POST /register returns 400 when registrar throws ClientRegistrationException')]
    public function testRegistrationWithRegistrarException(): void
    {
        $registrar = $this->createMock(ClientRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('register')
            ->willThrowException(new ClientRegistrationException('redirect_uris is required'));

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withBody($this->factory->createStream('{}'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('redirect_uris is required', $payload['error_description']);
    }

    #[TestDox('GET /.well-known/oauth-authorization-server enriches response with registration_endpoint')]
    public function testMetadataEnrichment(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $upstreamMetadata = [
            'issuer' => 'http://localhost:8000',
            'authorization_endpoint' => 'http://localhost:8000/authorize',
            'token_endpoint' => 'http://localhost:8000/token',
        ];

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createJsonHandler(200, $upstreamMetadata);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('http://localhost:8000/register', $payload['registration_endpoint']);
        $this->assertSame('http://localhost:8000/authorize', $payload['authorization_endpoint']);
    }

    #[TestDox('GET /.well-known/oauth-authorization-server preserves Cache-Control header')]
    public function testMetadataEnrichmentPreservesCacheControl(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createJsonHandler(200, ['issuer' => 'http://localhost:8000'], 'max-age=3600');

        $response = $middleware->process($request, $handler);

        $this->assertSame('max-age=3600', $response->getHeaderLine('Cache-Control'));
    }

    #[TestDox('GET /.well-known/oauth-authorization-server with non-200 status passes through unchanged')]
    public function testMetadataNon200PassesThrough(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createPassthroughHandler(500);

        $response = $middleware->process($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
    }

    #[TestDox('non-matching routes pass through to next handler')]
    public function testNonMatchingRoutePassesThrough(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/mcp');
        $handler = $this->createPassthroughHandler(204);

        $response = $middleware->process($request, $handler);

        $this->assertSame(204, $response->getStatusCode());
    }

    #[TestDox('constructor rejects empty localBaseUrl')]
    public function testConstructorRejectsEmptyBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientRegistrationMiddleware(
            $this->createStub(ClientRegistrarInterface::class),
            '',
            $this->factory,
            $this->factory,
        );
    }

    #[TestDox('localBaseUrl trailing slash is normalized in registration_endpoint')]
    public function testTrailingSlashNormalization(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);

        $middleware = new ClientRegistrationMiddleware(
            $registrar,
            'http://localhost:8000/',
            $this->factory,
            $this->factory,
        );

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createJsonHandler(200, ['issuer' => 'http://localhost:8000']);

        $response = $middleware->process($request, $handler);

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('http://localhost:8000/register', $payload['registration_endpoint']);
    }

    private function createMiddleware(ClientRegistrarInterface $registrar): ClientRegistrationMiddleware
    {
        return new ClientRegistrationMiddleware(
            $registrar,
            'http://localhost:8000',
            $this->factory,
            $this->factory,
        );
    }

    private function createPassthroughHandler(int $status): RequestHandlerInterface
    {
        $factory = $this->factory;

        return new class($factory, $status) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseFactoryInterface $factory,
                private readonly int $status,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse($this->status);
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonHandler(int $status, array $data, string $cacheControl = ''): RequestHandlerInterface
    {
        $factory = $this->factory;

        return new class($factory, $status, $data, $cacheControl) implements RequestHandlerInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(
                private readonly ResponseFactoryInterface $factory,
                private readonly int $status,
                private readonly array $data,
                private readonly string $cacheControl,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->factory->createResponse($this->status)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody((new Psr17Factory())->createStream(
                        json_encode($this->data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                    ));

                if ('' !== $this->cacheControl) {
                    $response = $response->withHeader('Cache-Control', $this->cacheControl);
                }

                return $response;
            }
        };
    }
}
