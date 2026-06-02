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

use Mcp\Server\Transport\Http\Middleware\JwksMiddleware;
use Mcp\Server\Transport\Http\OAuth\RsaSigningKey;
use PHPUnit\Framework\Attributes\TestDox;

class JwksMiddlewareTest extends MiddlewareTestCase
{
    #[TestDox('serves the public key as a JWK Set')]
    public function testServesJwks(): void
    {
        $middleware = new JwksMiddleware($this->signingKey('kid-1'));
        $request = $this->factory->createServerRequest('GET', 'https://mcp.example.com/.well-known/jwks.json');

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $body['keys']);
        $this->assertSame('kid-1', $body['keys'][0]['kid']);
    }

    #[TestDox('passes other requests through')]
    public function testPassthrough(): void
    {
        $middleware = new JwksMiddleware($this->signingKey('kid-1'));
        $request = $this->factory->createServerRequest('GET', 'https://mcp.example.com/other');

        $this->assertSame(204, $middleware->process($request, $this->handlerReturning(204))->getStatusCode());
    }

    private function signingKey(string $kid): RsaSigningKey
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        return new RsaSigningKey($pem, $kid);
    }
}
