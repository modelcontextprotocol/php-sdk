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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The token the authorization middleware validated reaches the handler, and only for its own request.
 */
final class AccessTokenFlowTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testHandshakeEraHandlerSeesTheTokenOfItsRequest(): void
    {
        $server = $this->server();

        $handshake = $this->request(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR), 'alice');
        $session = $this->serve($server, $handshake)->getHeaderLine('Mcp-Session-Id');

        $call = static fn (int $id): string => json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami', 'arguments' => new \stdClass()],
        ], \JSON_THROW_ON_ERROR);
        $headers = ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => ProtocolVersion::V2025_11_25->value];

        $this->assertSame('alice', $this->text($this->serve($server, $this->request($call(2), 'alice', $headers))));
        $this->assertSame('bob', $this->text($this->serve($server, $this->request($call(3), 'bob', $headers))));

        // Without the authorization middleware, nothing of the earlier requests is left in the session.
        $this->assertSame('anonymous', $this->text($this->serve($server, $this->request($call(4), null, $headers), [])));
    }

    public function testModernEraHandlerSeesTheToken(): void
    {
        $request = $this->request($this->envelopedCall(), 'carol', [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'whoami',
        ]);

        $this->assertSame('carol', $this->text($this->serve($this->server(), $request)));
    }

    public function testStatelessTransportHandsTheTokenOn(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addTool(self::whoami(...), name: 'whoami')
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $request = $this->request($this->envelopedCall(), 'dave', [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'whoami',
        ]);

        $transport = new StatelessHttpTransport($protocol, $this->factory, $this->factory, middleware: [$this->authorization()]);

        $this->assertSame('dave', $this->text($transport->handle($request)));
    }

    public static function whoami(RequestContext $context): string
    {
        return $context->getAccessToken()?->getSubject() ?? 'anonymous';
    }

    private function server(): Server
    {
        return Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addTool(self::whoami(...), name: 'whoami')
            ->build();
    }

    /**
     * @param list<MiddlewareInterface>|null $middleware
     */
    private function serve(Server $server, ServerRequestInterface $request, ?array $middleware = null): ResponseInterface
    {
        return $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory, middleware: $middleware ?? [$this->authorization()]));
    }

    private function authorization(): AuthorizationMiddleware
    {
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow(new AccessToken([], ['sub' => $accessToken]));
            }
        };

        return new AuthorizationMiddleware(
            $validator,
            new ProtectedResourceMetadata('http://localhost/mcp', ['http://localhost:8180']),
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, ?string $token, array $headers = []): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        if (null !== $token) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function envelopedCall(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'whoami',
                'arguments' => new \stdClass(),
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    private function text(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['result']['content'][0]['text'] ?? 'no result: '.$response->getBody();
    }
}
