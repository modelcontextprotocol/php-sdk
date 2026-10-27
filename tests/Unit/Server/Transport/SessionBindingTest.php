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
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A handshake-era session only serves the principal that opened it.
 */
final class SessionBindingTest extends TestCase
{
    private Psr17Factory $factory;
    private Server $server;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addTool(self::whoami(...), name: 'whoami')
            ->build();
    }

    public function testSessionServesTheSubjectThatOpenedIt(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.1')));
    }

    public function testRefreshedTokenKeepsTheSession(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.2')));
    }

    public function testAnotherSubjectIsAnsweredLikeAnUnknownSession(): void
    {
        $session = $this->initialize('alice.ide.1');
        $unknown = $this->call(Uuid::v4()->toRfc4122(), 'carol.ide.1');

        $response = $this->call($session, 'carol.ide.1');

        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame((string) $unknown->getBody(), (string) $response->getBody());
    }

    public function testAnotherClientOfTheSameSubjectIsRefused(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->assertSame(404, $this->call($session, 'alice.cli.1')->getStatusCode());
    }

    public function testRequestWithoutPrincipalIsRefused(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->assertSame(404, $this->call($session, null, [new PassthroughMiddleware()])->getStatusCode());
    }

    public function testDeleteOfAnotherPrincipalKeepsTheSession(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->serve($this->request('DELETE', '', 'carol.ide.1', $session));
        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.1')));

        $this->serve($this->request('DELETE', '', 'alice.ide.1', $session));
        $this->assertSame(404, $this->call($session, 'alice.ide.1')->getStatusCode());
    }

    public function testPrincipalSetByTheApplicationBindsTheSession(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, $request->getHeaderLine('X-User')));
            }
        };

        $initialize = $this->request('POST', $this->initializeRequest(), null)->withHeader('X-User', 'alice');
        $session = $this->serve($initialize, [$middleware])->getHeaderLine('Mcp-Session-Id');

        $this->assertSame('anonymous', $this->text($this->serve($this->request('POST', $this->toolCall(), null, $session)->withHeader('X-User', 'alice'), [$middleware])));
        $this->assertSame(404, $this->serve($this->request('POST', $this->toolCall(), null, $session)->withHeader('X-User', 'carol'), [$middleware])->getStatusCode());
    }

    public function testSessionOpenedWithoutPrincipalOnlyServesRequestsWithoutOne(): void
    {
        $passthrough = [new PassthroughMiddleware()];
        $session = $this->serve($this->request('POST', $this->initializeRequest(), null), $passthrough)->getHeaderLine('Mcp-Session-Id');

        $this->assertSame('anonymous', $this->text($this->call($session, null, $passthrough)));
        $this->assertSame(404, $this->call($session, 'alice.ide.1')->getStatusCode());
    }

    public static function whoami(RequestContext $context): string
    {
        return $context->getAccessToken()?->getSubject() ?? 'anonymous';
    }

    private function initialize(string $token): string
    {
        $session = $this->serve($this->request('POST', $this->initializeRequest(), $token))->getHeaderLine('Mcp-Session-Id');
        $this->assertNotSame('', $session);

        return $session;
    }

    /**
     * @param list<MiddlewareInterface>|null $middleware
     */
    private function call(string $session, ?string $token, ?array $middleware = null): ResponseInterface
    {
        return $this->serve($this->request('POST', $this->toolCall(), $token, $session), $middleware);
    }

    /**
     * @param list<MiddlewareInterface>|null $middleware
     */
    private function serve(ServerRequestInterface $request, ?array $middleware = null): ResponseInterface
    {
        return $this->server->run(new StreamableHttpTransport($request, $this->factory, $this->factory, middleware: $middleware ?? [$this->authorization()]));
    }

    /**
     * Tokens read `<subject>.<client>.<serial>`: tokens differing only by their serial are refreshes of each other.
     */
    private function authorization(): AuthorizationMiddleware
    {
        $validator = new class implements AuthorizationTokenValidatorInterface {
            public function validate(string $accessToken): AuthorizationResult
            {
                [$subject, $client] = explode('.', $accessToken);

                return AuthorizationResult::allow(new AccessToken([], ['iss' => 'http://localhost:8180', 'sub' => $subject, 'azp' => $client, 'jti' => $accessToken]));
            }
        };

        return new AuthorizationMiddleware(
            $validator,
            new ProtectedResourceMetadata('http://localhost/mcp', ['http://localhost:8180']),
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function request(string $method, string $body, ?string $token, ?string $session = null): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest($method, 'http://localhost/mcp')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        if (null !== $token) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }

        if (null !== $session) {
            $request = $request
                ->withHeader('Mcp-Session-Id', $session)
                ->withHeader('MCP-Protocol-Version', ProtocolVersion::V2025_11_25->value);
        }

        return $request;
    }

    private function initializeRequest(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    private function toolCall(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami', 'arguments' => new \stdClass()],
        ], \JSON_THROW_ON_ERROR);
    }

    private function text(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['result']['content'][0]['text'] ?? 'no result: '.$response->getBody();
    }
}
