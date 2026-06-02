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

use Mcp\Server\Transport\Http\Middleware\AuthorizationEndpointMiddleware;
use Mcp\Server\Transport\Http\OAuth\AutoApproveConsent;
use Mcp\Server\Transport\Http\OAuth\Client;
use Mcp\Server\Transport\Http\OAuth\InMemoryAuthorizationCodeStore;
use Mcp\Server\Transport\Http\OAuth\InMemoryClientRepository;
use Mcp\Server\Transport\Http\OAuth\NativeAuthorizationCodeIssuer;
use Mcp\Server\Transport\Http\OAuth\ResourceOwner;
use Mcp\Server\Transport\Http\OAuth\ResourceOwnerResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthorizationEndpointMiddlewareTest extends MiddlewareTestCase
{
    private const REDIRECT = 'https://client.example.com/callback';

    #[TestDox('issues a code and redirects with state when the request is valid and approved')]
    public function testHappyPath(): void
    {
        $codes = new InMemoryAuthorizationCodeStore();
        $middleware = $this->middleware($codes, $this->resolver(new ResourceOwner('user-1')));

        $response = $middleware->process($this->authorizeRequest(), $this->handlerReturning(404));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        parse_str(parse_url($response->getHeaderLine('Location'), \PHP_URL_QUERY) ?: '', $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame('xyz', $query['state']);
        $this->assertNotNull($codes->consume($query['code']));
    }

    #[TestDox('renders a 400 (no redirect) for an unknown client')]
    public function testUnknownClient(): void
    {
        $middleware = $this->middleware(new InMemoryAuthorizationCodeStore(), $this->resolver(new ResourceOwner('user-1')));
        $request = $this->authorizeRequest(['client_id' => 'nope']);

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
    }

    #[TestDox('renders a 400 (no redirect) for an unregistered redirect_uri')]
    public function testRedirectMismatch(): void
    {
        $middleware = $this->middleware(new InMemoryAuthorizationCodeStore(), $this->resolver(new ResourceOwner('user-1')));
        $request = $this->authorizeRequest(['redirect_uri' => 'https://evil.example.com/cb']);

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
    }

    #[TestDox('redirects with invalid_request when PKCE is missing')]
    public function testMissingPkceRedirectsError(): void
    {
        $middleware = $this->middleware(new InMemoryAuthorizationCodeStore(), $this->resolver(new ResourceOwner('user-1')));
        $params = $this->validParams();
        unset($params['code_challenge'], $params['code_challenge_method']);
        $request = $this->factory
            ->createServerRequest('GET', 'https://mcp.example.com/authorize')
            ->withQueryParams($params);

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->getHeaderLine('Location'), \PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame('xyz', $query['state']);
    }

    #[TestDox('delegates to the host login when the user is unauthenticated')]
    public function testUnauthenticated(): void
    {
        $middleware = $this->middleware(new InMemoryAuthorizationCodeStore(), $this->resolver(null));

        $response = $middleware->process($this->authorizeRequest(), $this->handlerReturning(404));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://mcp.example.com/login', $response->getHeaderLine('Location'));
    }

    private function middleware(InMemoryAuthorizationCodeStore $codes, ResourceOwnerResolverInterface $resolver): AuthorizationEndpointMiddleware
    {
        $clients = new InMemoryClientRepository([
            new Client('client-1', null, [self::REDIRECT], ['authorization_code'], ['mcp:tools'], Client::AUTH_METHOD_NONE),
        ]);

        return new AuthorizationEndpointMiddleware(
            $clients,
            new NativeAuthorizationCodeIssuer($codes),
            $resolver,
            new AutoApproveConsent(),
            ['mcp:tools'],
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function authorizeRequest(array $overrides = []): ServerRequestInterface
    {
        $params = array_merge($this->validParams(), $overrides);

        return $this->factory
            ->createServerRequest('GET', 'https://mcp.example.com/authorize')
            ->withQueryParams($params);
    }

    /**
     * @return array<string, string>
     */
    private function validParams(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'client-1',
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'scope' => 'mcp:tools',
            'state' => 'xyz',
        ];
    }

    private function resolver(?ResourceOwner $owner): ResourceOwnerResolverInterface
    {
        $factory = $this->factory;

        return new class($owner, $factory) implements ResourceOwnerResolverInterface {
            public function __construct(
                private ?ResourceOwner $owner,
                private \Nyholm\Psr7\Factory\Psr17Factory $factory,
            ) {
            }

            public function resolve(ServerRequestInterface $request): ?ResourceOwner
            {
                return $this->owner;
            }

            public function onUnauthenticated(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(302)->withHeader('Location', 'https://mcp.example.com/login');
            }
        };
    }
}
