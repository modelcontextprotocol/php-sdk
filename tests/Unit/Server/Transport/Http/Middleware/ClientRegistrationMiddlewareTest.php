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
use Mcp\Exception\InvalidArgumentException;
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
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(json_encode(['redirect_uris' => ['https://example.com/callback']])));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

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
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('not json'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('Request body must be valid JSON.', $payload['error_description']);
    }

    #[TestDox('POST /register with JSON array instead of object returns 400')]
    public function testRegistrationWithJsonArrayReturns400(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('["not","an","object"]'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('Request body must be a JSON object.', $payload['error_description']);
    }

    #[TestDox('POST /register with empty JSON array returns 400')]
    public function testRegistrationWithEmptyJsonArrayReturns400(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('[]'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('Request body must be a JSON object.', $payload['error_description']);
    }

    #[TestDox('POST /register with nested JSON objects passes associative arrays to registrar')]
    public function testRegistrationWithNestedObjectsPassesAssociativeArrays(): void
    {
        $registrar = $this->createMock(ClientRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('register')
            ->with($this->callback(function (array $data): bool {
                // Nested objects must be associative arrays, not stdClass
                $this->assertIsArray($data['jwks']);
                $this->assertIsArray($data['jwks']['keys'][0]);
                $this->assertSame('RSA', $data['jwks']['keys'][0]['kty']);

                return true;
            }))
            ->willReturn(['client_id' => 'nested-client']);

        $middleware = $this->createMiddleware($registrar);

        $body = json_encode([
            'redirect_uris' => ['https://example.com/callback'],
            'jwks' => ['keys' => [['kty' => 'RSA', 'n' => 'abc', 'e' => 'AQAB']]],
        ]);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(201, $response->getStatusCode());
    }

    #[TestDox('POST /register error responses include Cache-Control: no-store')]
    public function testRegistrationErrorResponsesIncludeCacheControl(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        // Invalid JSON
        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('not json'));
        $response = $middleware->process($request, $this->createPassthroughHandler(404));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        // JSON array (not object)
        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('["array"]'));
        $response = $middleware->process($request, $this->createPassthroughHandler(404));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
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
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{}'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('redirect_uris is required', $payload['error_description']);
    }

    #[TestDox('POST /register without application/json Content-Type returns 400')]
    public function testRegistrationRejectsNonJsonContentType(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream('key=value'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client_metadata', $payload['error']);
        $this->assertSame('Content-Type must be application/json.', $payload['error_description']);
    }

    #[TestDox('POST /register uses error code from ClientRegistrationException')]
    public function testRegistrationUsesCustomErrorCode(): void
    {
        $registrar = $this->createMock(ClientRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('register')
            ->willThrowException(new ClientRegistrationException('Invalid redirect URI', 'invalid_redirect_uri'));

        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('POST', 'http://localhost:8000/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{}'));

        $response = $middleware->process($request, $this->createPassthroughHandler(404));

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_redirect_uri', $payload['error']);
        $this->assertSame('Invalid redirect URI', $payload['error_description']);
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

    #[TestDox('GET /.well-known/oauth-authorization-server preserves original response headers')]
    public function testMetadataEnrichmentPreservesOriginalHeaders(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createJsonHandler(200, ['issuer' => 'http://localhost:8000'], 'max-age=3600', [
            'X-Custom' => 'preserved',
            'Vary' => 'Origin',
        ]);

        $response = $middleware->process($request, $handler);

        $this->assertSame('max-age=3600', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('preserved', $response->getHeaderLine('X-Custom'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    #[TestDox('GET /.well-known/oauth-authorization-server removes stale Content-Length after body mutation')]
    public function testMetadataEnrichmentRemovesContentLength(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createJsonHandler(200, ['issuer' => 'http://localhost:8000'], '', [
            'Content-Length' => '42',
        ]);

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader('Content-Length'));
    }

    #[TestDox('GET /.well-known/oauth-authorization-server with invalid JSON body rewinds stream before returning')]
    public function testMetadataEnrichmentRewindsStreamOnInvalidJsonBody(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createPlainTextHandler(200, 'not json');

        $response = $middleware->process($request, $handler);

        $this->assertSame('not json', $response->getBody()->getContents());
    }

    #[TestDox('GET /.well-known/oauth-authorization-server with non-object JSON body rewinds stream before returning')]
    public function testMetadataEnrichmentRewindsStreamOnNonObjectJsonBody(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createPlainTextHandler(200, '"just a string"');

        $response = $middleware->process($request, $handler);

        $this->assertSame('"just a string"', $response->getBody()->getContents());
    }

    #[TestDox('GET /.well-known/oauth-authorization-server with JSON array body passes through unchanged')]
    public function testMetadataEnrichmentPassesThroughJsonArrayBody(): void
    {
        $registrar = $this->createStub(ClientRegistrarInterface::class);
        $middleware = $this->createMiddleware($registrar);

        $request = $this->factory->createServerRequest('GET', 'http://localhost:8000/.well-known/oauth-authorization-server');
        $handler = $this->createPlainTextHandler(200, '["not","an","object"]');

        $response = $middleware->process($request, $handler);

        $this->assertSame('["not","an","object"]', $response->getBody()->getContents());
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
        $this->expectException(InvalidArgumentException::class);

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
     * @param array<string, mixed>  $data
     * @param array<string, string> $extraHeaders
     */
    private function createJsonHandler(int $status, array $data, string $cacheControl = '', array $extraHeaders = []): RequestHandlerInterface
    {
        $factory = $this->factory;

        return new class($factory, $status, $data, $cacheControl, $extraHeaders) implements RequestHandlerInterface {
            /**
             * @param array<string, mixed>  $data
             * @param array<string, string> $extraHeaders
             */
            public function __construct(
                private readonly ResponseFactoryInterface $factory,
                private readonly int $status,
                private readonly array $data,
                private readonly string $cacheControl,
                private readonly array $extraHeaders,
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

                foreach ($this->extraHeaders as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }

    private function createPlainTextHandler(int $status, string $body): RequestHandlerInterface
    {
        $factory = $this->factory;

        return new class($factory, $status, $body) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseFactoryInterface $factory,
                private readonly int $status,
                private readonly string $body,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse($this->status)
                    ->withHeader('Content-Type', 'text/plain')
                    ->withBody((new Psr17Factory())->createStream($this->body));
            }
        };
    }
}
