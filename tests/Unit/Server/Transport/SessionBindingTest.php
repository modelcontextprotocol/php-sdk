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

use Mcp\Exception\SessionStoreException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Handler\Request\PingHandler;
use Mcp\Server\Protocol;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingSessionLock;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const PING = '{"jsonrpc":"2.0","id":2,"method":"ping"}';
    private const QUEUED_URI = 'urn:queued-for-alice';

    private Psr17Factory $factory;
    private UnreadableSessionStore $store;
    private Server $server;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->store = new UnreadableSessionStore();
        $this->server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setSession($this->store)
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
        $unknown = $this->serve($this->request('DELETE', '', 'carol.ide.1', Uuid::v4()->toRfc4122()));

        $response = $this->serve($this->request('DELETE', '', 'carol.ide.1', $session));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $response->getStatusCode());
        $this->assertSame((string) $unknown->getBody(), (string) $response->getBody());
        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.1')));

        $this->serve($this->request('DELETE', '', 'alice.ide.1', $session));
        $this->assertSame(404, $this->call($session, 'alice.ide.1')->getStatusCode());
    }

    public function testSessionThatCannotBeReadIsRetriedByEveryone(): void
    {
        $session = $this->initialize('alice.ide.1');
        $passthrough = [new PassthroughMiddleware()];

        $this->store->unreadable = true;
        $this->assertSame(503, $this->call($session, 'alice.ide.1')->getStatusCode());
        $this->assertSame(503, $this->call($session, null, $passthrough)->getStatusCode());
        $this->assertSame(503, $this->serve($this->request('DELETE', '', 'alice.ide.1', $session))->getStatusCode());
        $this->assertSame(503, $this->serve($this->request('DELETE', '', null, $session), $passthrough)->getStatusCode());
        $this->store->unreadable = false;

        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.1')));
    }

    public function testSessionThatCannotBeReadIsRetriedWithASessionLock(): void
    {
        $log = [];
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setSession($this->store)
            ->setSessionLock(new RecordingSessionLock($log))
            ->build();
        $session = $this->server($server, $this->request('POST', $this->initializeRequest(), 'alice.ide.1'))->getHeaderLine('Mcp-Session-Id');
        $log = [];

        $this->store->unreadable = true;
        $this->assertSame(503, $this->server($server, $this->request('POST', $this->toolCall(), 'alice.ide.1', $session))->getStatusCode());
        $this->assertSame([], $log);
    }

    public function testDeleteDuringAStoreOutageKeepsTheSession(): void
    {
        $session = $this->initialize('alice.ide.1');

        $this->store->unavailable = true;
        $this->assertSame(503, $this->serve($this->request('DELETE', '', 'alice.ide.1', $session))->getStatusCode());
        $this->store->unavailable = false;

        $this->assertSame('alice', $this->text($this->call($session, 'alice.ide.1')));
    }

    public function testSessionFromBeforeTheUpgradeIsNotFound(): void
    {
        $session = Uuid::v4();
        $this->store->write($session, json_encode(['initialized' => true], \JSON_THROW_ON_ERROR));

        $this->assertSame(404, $this->call($session->toRfc4122(), 'alice.ide.1')->getStatusCode());
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

    public function testAccessTokenSetByTheApplicationBindsTheSession(): void
    {
        $session = $this->openWith([AccessToken::class => new AccessToken([], ['sub' => 'alice'])]);

        $this->assertSame('alice', $this->text($this->callWith($session, [AccessToken::class => new AccessToken([], ['sub' => 'alice'])])));
        $this->assertSame(404, $this->callWith($session, [AccessToken::class => new AccessToken([], ['sub' => 'carol'])])->getStatusCode());
    }

    public function testPrincipalAttributeWinsOverTheAccessToken(): void
    {
        $alice = [StreamableHttpTransport::PRINCIPAL_ATTRIBUTE => 'alice'];
        $session = $this->openWith($alice + [AccessToken::class => new AccessToken([], ['sub' => 'device-1'])]);

        $this->assertSame('device-2', $this->text($this->callWith($session, $alice + [AccessToken::class => new AccessToken([], ['sub' => 'device-2'])])));
    }

    public function testTokensWithoutSubjectOfAnotherClientAreRefused(): void
    {
        $session = $this->openWith([AccessToken::class => new AccessToken([], ['client_id' => 'a'])]);

        $this->assertSame(200, $this->callWith($session, [AccessToken::class => new AccessToken([], ['client_id' => 'a'])])->getStatusCode());
        $this->assertSame(404, $this->callWith($session, [AccessToken::class => new AccessToken([], ['client_id' => 'b'])])->getStatusCode());
    }

    public function testTokenWithAClaimThatIsNotUtf8BindsTheSession(): void
    {
        $owner = [AccessToken::class => new AccessToken([], ['sub' => "\xB1"])];
        $other = [AccessToken::class => new AccessToken([], ['sub' => "\xB2"])];
        $ping = '{"jsonrpc":"2.0","id":2,"method":"ping"}';
        $session = $this->openWith($owner);

        $this->assertSame(200, $this->serveWith($this->request('POST', $ping, null, $session), $owner)->getStatusCode());
        $this->assertSame(404, $this->serveWith($this->request('POST', $ping, null, $session), $other)->getStatusCode());
        $this->assertSame(404, $this->serveWith($this->request('DELETE', '', null, $session), $other)->getStatusCode());
        $this->assertSame(200, $this->serveWith($this->request('DELETE', '', null, $session), $owner)->getStatusCode());
    }

    public function testTokenWithoutClaimsDoesNotShareSessionsWithRequestsWithoutToken(): void
    {
        $session = $this->openWith([AccessToken::class => new AccessToken()]);

        $this->assertSame(404, $this->callWith($session, [])->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidPrincipals(): iterable
    {
        yield 'an integer' => [42];
        yield 'an empty string' => [''];
        yield 'a stringable object' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 'alice';
            }
        }];
    }

    #[DataProvider('provideInvalidPrincipals')]
    public function testInvalidPrincipalAttributeIsRefused(mixed $principal): void
    {
        $attributes = [StreamableHttpTransport::PRINCIPAL_ATTRIBUTE => $principal];

        $response = $this->serveWith($this->request('POST', $this->initializeRequest(), null), $attributes);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Mcp-Session-Id'));

        $session = $this->openWith([]);
        $this->assertSame(500, $this->serveWith($this->request('DELETE', '', null, $session), $attributes)->getStatusCode());
        $this->assertSame(200, $this->callWith($session, [])->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInputsRefusedBeforeTheSessionIsChecked(): iterable
    {
        yield 'malformed JSON' => ['{'];
        yield 'an initialize request' => ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"client","version":"1.0.0"}}}'];
    }

    #[DataProvider('provideInputsRefusedBeforeTheSessionIsChecked')]
    public function testInputRefusedBeforeTheSessionIsCheckedLeavesItsQueueAlone(string $input): void
    {
        $protocol = $this->protocol();
        $session = $this->sessionWithQueuedMessage($protocol);

        $response = $this->post($protocol, $input, $session, 'carol');

        $this->assertStringNotContainsString(self::QUEUED_URI, (string) $response->getBody());
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(self::QUEUED_URI, (string) $this->post($protocol, self::PING, $session, 'alice')->getBody());
    }

    public function testInternalErrorBeforeTheSessionIsCheckedLeavesItsQueueAlone(): void
    {
        $session = $this->sessionWithQueuedMessage($this->protocol());
        $this->store->gcFails = true;

        $response = $this->post($this->protocol(gcProbability: 1), self::PING, $session, 'carol');

        $this->assertStringNotContainsString(self::QUEUED_URI, (string) $response->getBody());
        $this->assertSame(500, $response->getStatusCode());

        $this->store->gcFails = false;
        $this->assertStringContainsString(self::QUEUED_URI, (string) $this->post($this->protocol(), self::PING, $session, 'alice')->getBody());
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

    /**
     * @param array<string, mixed> $attributes
     */
    private function openWith(array $attributes): string
    {
        $session = $this->serveWith($this->request('POST', $this->initializeRequest(), null), $attributes)->getHeaderLine('Mcp-Session-Id');
        $this->assertNotSame('', $session);

        return $session;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function callWith(string $session, array $attributes): ResponseInterface
    {
        return $this->serveWith($this->request('POST', $this->toolCall(), null, $session), $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function serveWith(ServerRequestInterface $request, array $attributes): ResponseInterface
    {
        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $this->serve($request, [new PassthroughMiddleware()]);
    }

    private function protocol(int $gcProbability = 0): Protocol
    {
        return new Protocol(
            requestHandlers: [new PingHandler()],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: new SessionManager($this->store, gcProbability: $gcProbability, gcDivisor: 1),
        );
    }

    /**
     * Opens a session of alice, with a message queued outside of any request.
     */
    private function sessionWithQueuedMessage(Protocol $protocol): Uuid
    {
        $session = new Session($this->store);
        $session->set('_mcp.principal', hash('sha256', 'alice'));
        $session->set('initialized', true);
        $protocol->sendNotification(new ResourceUpdatedNotification(self::QUEUED_URI), $session);
        $session->save();

        return $session->getId();
    }

    private function post(Protocol $protocol, string $input, Uuid $session, string $principal): ResponseInterface
    {
        $request = $this->request('POST', $input, null, $session->toRfc4122())
            ->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, $principal);
        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory, middleware: [new PassthroughMiddleware()]);
        $protocol->connect($transport);

        return $transport->listen();
    }

    private function server(Server $server, ServerRequestInterface $request): ResponseInterface
    {
        return $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory, middleware: [$this->authorization()]));
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

/**
 * Finds sessions but fails to read them while $unreadable is set, like a cache that is reachable but fails on get().
 * Fails to look them up while $unavailable is set, like a cache that is down.
 */
final class UnreadableSessionStore extends InMemorySessionStore
{
    public bool $unreadable = false;
    public bool $unavailable = false;
    public bool $gcFails = false;

    public function gc(): array
    {
        if ($this->gcFails) {
            throw new SessionStoreException('Session store is unavailable.');
        }

        return parent::gc();
    }

    public function exists(Uuid $id): bool
    {
        if ($this->unavailable) {
            throw new SessionStoreException('Session store is unavailable.');
        }

        return parent::exists($id);
    }

    public function read(Uuid $id): string|false
    {
        return $this->unreadable ? false : parent::read($id);
    }
}
