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

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class AuthorizationMiddlewareTest extends MiddlewareTestCase
{
    private const METADATA_URL = 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp';

    public function testMissingTokenIsChallengedWithMetadataAndScopes(): void
    {
        $response = $this->middleware()->process($this->request(), $this->passthroughHandler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Bearer resource_metadata="'.self::METADATA_URL.'", scope="mcp:read"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    public function testMetadataUrlDoesNotFollowHostHeader(): void
    {
        $request = $this->factory->createServerRequest('POST', 'http://evil.example.com/mcp')->withHeader('Host', 'evil.example.com');

        $response = $this->middleware()->process($request, $this->passthroughHandler);

        $this->assertStringContainsString('resource_metadata="'.self::METADATA_URL.'"', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedHeaders(): iterable
    {
        yield 'other scheme' => ['Basic dXNlcjpwYXNz'];
        yield 'empty token' => ['Bearer '];
        yield 'token with space' => ['Bearer abc def'];
        yield 'token with quote' => ['Bearer abc"def'];
    }

    #[DataProvider('provideMalformedHeaders')]
    public function testMalformedHeaderIsBadRequest(string $header): void
    {
        $response = $this->middleware()->process($this->request()->withHeader('Authorization', $header), $this->passthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testInvalidTokenIsChallengedWithValidatorError(): void
    {
        $validator = $this->validator(AuthorizationResult::unauthorized('invalid_token', "Token \"expired\"\r\n."));

        $response = $this->middleware($validator)->process($this->request('Bearer abc'), $this->passthroughHandler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Bearer resource_metadata="'.self::METADATA_URL.'", scope="mcp:read", error="invalid_token", error_description="Token \"expired\"."',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    public function testValidTokenReachesHandlerAsAttribute(): void
    {
        $token = new AccessToken(['mcp:read'], ['sub' => 'user-1']);
        $handler = $this->capturingHandler();

        $response = $this->middleware($this->validator(AuthorizationResult::allow($token)))->process($this->request('Bearer abc.def-ghi_~+/='), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($token, $handler->request?->getAttribute(AccessToken::class));
    }

    public function testDefaultScopesAreEnforcedWithoutReadingTheBody(): void
    {
        $policy = new ScopePolicy(default: ['mcp:read', 'mcp:write']);
        $middleware = $this->middleware($this->validator(AuthorizationResult::allow(new AccessToken(['mcp:read']))), $policy);

        $response = $middleware->process($this->request('Bearer abc')->withMethod('GET'), $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Bearer resource_metadata="'.self::METADATA_URL.'", scope="mcp:read mcp:write", error="insufficient_scope", error_description="The access token lacks a required scope."',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    public function testToolScopesAreChallengedAsOneSet(): void
    {
        $policy = new ScopePolicy(default: ['mcp:read'], tools: ['delete_file' => ['files:write']]);
        $middleware = $this->middleware($this->validator(AuthorizationResult::allow(new AccessToken(['mcp:read']))), $policy);

        $response = $middleware->process($this->request('Bearer abc', $this->toolCall('delete_file')), $this->passthroughHandler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('scope="mcp:read files:write"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testHandedOnTokenIncludesImpliedScopes(): void
    {
        $middleware = $this->middleware($this->validator(AuthorizationResult::allow(new AccessToken(['files:admin'], ['sub' => 'user-1']))), new ScopePolicy(
            implies: ['files:admin' => ['files:write']],
        ));
        $handler = $this->capturingHandler();

        $middleware->process($this->request('Bearer abc'), $handler);

        $token = $handler->request?->getAttribute(AccessToken::class);
        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertTrue($token->hasScope('files:write'));
        $this->assertSame('user-1', $token->getSubject());
    }

    public function testBodyIsHandedOnUnchanged(): void
    {
        $middleware = $this->middleware($this->validator(AuthorizationResult::allow(new AccessToken(['files:admin']))), new ScopePolicy(
            tools: ['delete_file' => ['files:write']],
            implies: ['files:admin' => ['files:write']],
        ));
        $body = $this->toolCall('delete_file');
        $handler = $this->capturingHandler();

        $response = $middleware->process($this->request('Bearer abc', $body), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($body, $handler->request?->getBody()->__toString());
    }

    public function testOversizedBodyIsRejected(): void
    {
        $middleware = new AuthorizationMiddleware(
            $this->validator(AuthorizationResult::allow(new AccessToken())),
            $this->metadata(),
            new ScopePolicy(tools: ['x' => ['y']]),
            $this->factory,
            $this->factory,
            maxBodyBytes: 10,
        );

        $response = $middleware->process($this->request('Bearer abc', $this->toolCall('x')), $this->passthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
    }

    private function middleware(?AuthorizationTokenValidatorInterface $validator = null, ?ScopePolicy $policy = null): AuthorizationMiddleware
    {
        return new AuthorizationMiddleware(
            $validator ?? $this->validator(AuthorizationResult::unauthorized()),
            $this->metadata(),
            $policy,
            $this->factory,
            $this->factory,
        );
    }

    private function metadata(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata('https://mcp.example.com/mcp', ['https://auth.example.com'], ['mcp:read']);
    }

    private function validator(AuthorizationResult $result): AuthorizationTokenValidatorInterface
    {
        return new class($result) implements AuthorizationTokenValidatorInterface {
            public function __construct(private AuthorizationResult $result)
            {
            }

            public function validate(string $accessToken): AuthorizationResult
            {
                return $this->result;
            }
        };
    }

    private function request(?string $authorization = null, string $body = ''): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($this->factory->createStream($body));

        return null === $authorization ? $request : $request->withHeader('Authorization', $authorization);
    }

    private function toolCall(string $name): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => new \stdClass()]], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return RequestHandlerInterface&object{request: ?ServerRequestInterface}
     */
    private function capturingHandler(): RequestHandlerInterface
    {
        return new class($this->passthroughHandler) implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function __construct(private RequestHandlerInterface $inner)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return $this->inner->handle($request);
            }
        };
    }
}
