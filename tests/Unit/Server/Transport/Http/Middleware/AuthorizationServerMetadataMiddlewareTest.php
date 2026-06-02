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

use Mcp\Server\Transport\Http\Middleware\AuthorizationServerMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationServerMetadata;
use PHPUnit\Framework\Attributes\TestDox;

class AuthorizationServerMetadataMiddlewareTest extends MiddlewareTestCase
{
    #[TestDox('serves RFC 8414 metadata on the well-known path')]
    public function testServesMetadata(): void
    {
        $middleware = new AuthorizationServerMetadataMiddleware(new AuthorizationServerMetadata('https://mcp.example.com'));
        $request = $this->factory->createServerRequest('GET', 'https://mcp.example.com/.well-known/oauth-authorization-server');

        $response = $middleware->process($request, $this->handlerReturning(404));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('https://mcp.example.com', $body['issuer']);
    }

    #[TestDox('passes other requests through')]
    public function testPassthrough(): void
    {
        $middleware = new AuthorizationServerMetadataMiddleware(new AuthorizationServerMetadata('https://mcp.example.com'));
        $request = $this->factory->createServerRequest('GET', 'https://mcp.example.com/other');

        $response = $middleware->process($request, $this->handlerReturning(204));

        $this->assertSame(204, $response->getStatusCode());
    }
}
