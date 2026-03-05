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

use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests OAuthRequestMetaMiddleware propagation of OAuth attributes to JSON-RPC meta.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class OAuthRequestMetaMiddlewareTest extends TestCase
{
    #[TestDox('oauth request attributes are copied to json-rpc params _meta')]
    public function testInjectsOauthAttributesIntoSingleRequest(): void
    {
        $factory = new Psr17Factory();
        $middleware = new OAuthRequestMetaMiddleware($factory);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
            ],
        ];

        $request = $factory
            ->createServerRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)))
            ->withAttribute('oauth.claims', ['sub' => 'user-1'])
            ->withAttribute('oauth.scopes', ['openid', 'profile'])
            ->withAttribute('oauth.subject', 'user-1')
            ->withAttribute('not_oauth', 'ignored');

        $response = $middleware->process($request, $this->createEchoHandler($factory));
        $decoded = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['sub' => 'user-1'], $decoded['params']['_meta']['oauth']['oauth.claims']);
        $this->assertSame(['openid', 'profile'], $decoded['params']['_meta']['oauth']['oauth.scopes']);
        $this->assertSame('user-1', $decoded['params']['_meta']['oauth']['oauth.subject']);
        $this->assertArrayNotHasKey('not_oauth', $decoded['params']['_meta']['oauth']);
    }

    #[TestDox('existing _meta is preserved and oauth keys are merged')]
    public function testMergesWithExistingMeta(): void
    {
        $factory = new Psr17Factory();
        $middleware = new OAuthRequestMetaMiddleware($factory);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'trace_id' => 'trace-1',
                    'oauth' => [
                        'client_hint' => 'web',
                        'oauth.subject' => 'spoofed',
                    ],
                ],
            ],
        ];

        $request = $factory
            ->createServerRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)))
            ->withAttribute('oauth.subject', 'trusted-user')
            ->withAttribute('oauth.scopes', ['mcp.read']);

        $response = $middleware->process($request, $this->createEchoHandler($factory));
        $decoded = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('trace-1', $decoded['params']['_meta']['trace_id']);
        $this->assertSame('web', $decoded['params']['_meta']['oauth']['client_hint']);
        $this->assertSame('trusted-user', $decoded['params']['_meta']['oauth']['oauth.subject']);
        $this->assertSame(['mcp.read'], $decoded['params']['_meta']['oauth']['oauth.scopes']);
    }

    #[TestDox('oauth request attributes are copied for each batch entry')]
    public function testInjectsOauthAttributesIntoBatchRequest(): void
    {
        $factory = new Psr17Factory();
        $middleware = new OAuthRequestMetaMiddleware($factory);

        $payload = [
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ],
        ];

        $request = $factory
            ->createServerRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)))
            ->withAttribute('oauth.subject', 'batch-user');

        $response = $middleware->process($request, $this->createEchoHandler($factory));
        $decoded = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('batch-user', $decoded[0]['params']['_meta']['oauth']['oauth.subject']);
        $this->assertSame('batch-user', $decoded[1]['params']['_meta']['oauth']['oauth.subject']);
    }

    #[TestDox('request without oauth attributes passes through unchanged')]
    public function testNoOauthAttributesPassThrough(): void
    {
        $factory = new Psr17Factory();
        $middleware = new OAuthRequestMetaMiddleware($factory);

        $body = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{}}';

        $request = $factory
            ->createServerRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream($body));

        $response = $middleware->process($request, $this->createEchoHandler($factory));

        $this->assertSame($body, $response->getBody()->__toString());
    }

    #[TestDox('non post requests pass through unchanged')]
    public function testNonPostPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $middleware = new OAuthRequestMetaMiddleware($factory);

        $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        $request = $factory
            ->createServerRequest('GET', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream($body))
            ->withAttribute('oauth.subject', 'user-1');

        $response = $middleware->process($request, $this->createEchoHandler($factory));

        $this->assertSame($body, $response->getBody()->__toString());
    }

    private function createEchoHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory
                    ->createResponse(200)
                    ->withBody($this->factory->createStream($request->getBody()->__toString()));
            }
        };
    }
}
