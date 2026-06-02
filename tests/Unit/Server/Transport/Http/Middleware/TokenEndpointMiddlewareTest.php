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

use Mcp\Exception\OAuthException;
use Mcp\Server\Transport\Http\Middleware\TokenEndpointMiddleware;
use Mcp\Server\Transport\Http\OAuth\TokenGranterInterface;
use Mcp\Server\Transport\Http\OAuth\TokenResponse;
use PHPUnit\Framework\Attributes\TestDox;

class TokenEndpointMiddlewareTest extends MiddlewareTestCase
{
    #[TestDox('returns the token response as non-cacheable JSON')]
    public function testSuccess(): void
    {
        $granter = $this->granter(static fn (): TokenResponse => new TokenResponse('jwt-token', 3600, ['mcp:tools'], 'refresh-1'));
        $middleware = new TokenEndpointMiddleware($granter);

        $request = $this->factory
            ->createServerRequest('POST', 'https://mcp.example.com/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream('grant_type=authorization_code&code=abc&client_id=c1'));

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('jwt-token', $body['access_token']);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame('refresh-1', $body['refresh_token']);
    }

    #[TestDox('maps an OAuthException to an RFC 6749 error response')]
    public function testError(): void
    {
        $granter = $this->granter(static fn () => throw OAuthException::invalidGrant('bad code'));
        $middleware = new TokenEndpointMiddleware($granter);

        $request = $this->factory
            ->createServerRequest('POST', 'https://mcp.example.com/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream('grant_type=authorization_code'));

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_grant', $body['error']);
    }

    #[TestDox('decodes HTTP Basic client credentials into the params')]
    public function testBasicAuth(): void
    {
        $captured = null;
        $granter = $this->granter(static function (string $grant, array $params) use (&$captured): TokenResponse {
            $captured = $params;

            return new TokenResponse('jwt', 3600);
        });
        $middleware = new TokenEndpointMiddleware($granter);

        $request = $this->factory
            ->createServerRequest('POST', 'https://mcp.example.com/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Authorization', 'Basic '.base64_encode('client-1:s3cret'))
            ->withBody($this->factory->createStream('grant_type=refresh_token&refresh_token=r1'));

        $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame('client-1', $captured['client_id']);
        $this->assertSame('s3cret', $captured['client_secret']);
    }

    #[TestDox('passes non-token requests through')]
    public function testPassthrough(): void
    {
        $middleware = new TokenEndpointMiddleware($this->granter(static fn () => new TokenResponse('x', 1)));
        $request = $this->factory->createServerRequest('GET', 'https://mcp.example.com/token');

        $this->assertSame(204, $middleware->process($request, $this->handlerReturning(204))->getStatusCode());
    }

    private function granter(callable $grant): TokenGranterInterface
    {
        return new class($grant) implements TokenGranterInterface {
            /** @param callable(string, array<string,mixed>): TokenResponse $grant */
            public function __construct(private $grant)
            {
            }

            public function grant(string $grantType, array $params): TokenResponse
            {
                return ($this->grant)($grantType, $params);
            }
        };
    }
}
