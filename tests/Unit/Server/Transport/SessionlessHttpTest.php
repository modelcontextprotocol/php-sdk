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

use Mcp\Exception\SessionRequiredException;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Handshake-era traffic of a server built without {@see Server\Builder::setSession()}.
 *
 * Each request runs on a server of its own, as it would on a PHP worker behind a load balancer.
 */
final class SessionlessHttpTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[TestDox('initialize is answered without a session id')]
    public function testInitializeIsAnsweredWithoutASessionId(): void
    {
        $response = $this->send('POST', $this->initialize());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));
        $this->assertSame(ProtocolVersion::V2025_11_25->value, self::decode($response)['result']['protocolVersion']);
    }

    #[TestDox('setSession() turns sessions on')]
    public function testSetSessionTurnsSessionsOn(): void
    {
        $server = Server::builder()->setServerInfo('sessions', '1.0.0')->setSession(new InMemorySessionStore())->build();
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($this->initialize()));

        $response = $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));
    }

    #[TestDox('initialize does not offer resource subscriptions, whose updates would reach the client outside a request')]
    public function testInitializeDoesNotOfferResourceSubscriptions(): void
    {
        $capabilities = self::decode($this->send('POST', $this->initialize()))['result']['capabilities'];

        $this->assertSame([], $capabilities['resources']);
        $this->assertArrayHasKey('logging', $capabilities);
    }

    #[TestDox('requests after initialize are served without a session id')]
    public function testRequestsAreServedWithoutASessionId(): void
    {
        $this->send('POST', $this->initialize());

        $list = $this->send('POST', $this->request('tools/list'));
        $call = $this->send('POST', $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hello']]));

        $this->assertSame(200, $list->getStatusCode());
        $this->assertContains('echo', array_column(self::decode($list)['result']['tools'], 'name'));
        $this->assertSame(200, $call->getStatusCode());
        $this->assertSame('hello', self::decode($call)['result']['content'][0]['text']);
        $this->assertFalse($list->hasHeader(StreamableHttpTransport::SESSION_HEADER));
        $this->assertFalse($call->hasHeader(StreamableHttpTransport::SESSION_HEADER));
    }

    #[TestDox('a session id sent by the client is ignored instead of refused')]
    public function testSessionIdOfTheClientIsIgnored(): void
    {
        $response = $this->send('POST', $this->request('tools/list'), [StreamableHttpTransport::SESSION_HEADER => 'f0e1d2c3-b4a5-4697-8879-6a5b4c3d2e1f']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('tools', self::decode($response)['result']);
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));
    }

    #[TestDox('GET and DELETE are answered with 405, naming only POST as the method to use')]
    public function testGetAndDeleteAreNotAllowed(): void
    {
        $get = $this->send('GET', headers: ['Accept' => 'text/event-stream']);
        $delete = $this->send('DELETE', headers: [StreamableHttpTransport::SESSION_HEADER => 'f0e1d2c3-b4a5-4697-8879-6a5b4c3d2e1f']);

        $this->assertSame(405, $get->getStatusCode());
        $this->assertSame('POST, OPTIONS', $get->getHeaderLine('Allow'));
        $this->assertSame(405, $delete->getStatusCode());
        $this->assertSame('POST, OPTIONS', $delete->getHeaderLine('Allow'));
    }

    #[TestDox('notifications sent during a request reach the client on its stream, without a session id')]
    public function testNotificationsDuringARequestAreStreamed(): void
    {
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'notify', 'arguments' => new \stdClass()]));

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));

        $stream = $this->readStream($response);

        $this->assertSame(2, substr_count($stream, 'notifications\/progress'));
        $this->assertStringContainsString('"text":"done"', $stream);
    }

    #[TestDox('$method fails loud instead of waiting for an answer that cannot be matched')]
    #[DataProvider('provideRequestsToTheClient')]
    public function testRequestToTheClientFailsLoud(string $tool, string $method): void
    {
        $started = microtime(true);
        $response = $this->send('POST', $this->request('tools/call', ['name' => $tool, 'arguments' => new \stdClass()]));

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['code' => -32603, 'message' => 'Requests to the client need a session; configure one with Builder::setSession().'], self::decode($response)['error']);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRequestsToTheClient(): iterable
    {
        yield 'elicit' => ['elicit', 'elicit()'];
        yield 'elicit-url' => ['elicit-url', 'elicitUrl()'];
        yield 'sample' => ['sample', 'sample()'];
        yield 'list-roots' => ['list-roots', 'listRoots()'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[TestDox('a request to the client from $method tells the client that it needs a session')]
    #[DataProvider('provideOtherHandlersAskingTheClient')]
    public function testOtherHandlersTellTheClientThatTheyNeedASession(string $method, array $params): void
    {
        $response = $this->send('POST', $this->request($method, $params));

        $this->assertSame(['code' => -32603, 'message' => 'Requests to the client need a session; configure one with Builder::setSession().'], self::decode($response)['error']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideOtherHandlersAskingTheClient(): iterable
    {
        yield 'prompts/get' => ['prompts/get', ['name' => 'ask', 'arguments' => new \stdClass()]];
        yield 'resources/read' => ['resources/read', ['uri' => 'config://ask']];
    }

    #[TestDox('a request to the client after a notification fails loud too')]
    public function testRequestToTheClientAfterANotificationFailsLoud(): void
    {
        $started = microtime(true);
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'notify-then-elicit', 'arguments' => new \stdClass()]));
        $stream = $this->readStream($response);

        $this->assertStringContainsString('notifications\/progress', $stream);
        $this->assertStringContainsString('"message":"Requests to the client need a session; configure one with Builder::setSession()."', $stream);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    #[TestDox('an input-required result fails loud, as the client cannot be asked for the input')]
    public function testInputRequiredResultFailsLoud(): void
    {
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'ask-for-input', 'arguments' => new \stdClass()]));

        $this->assertSame([
            'code' => -32603,
            'message' => 'Asking the client for input needs a session, and this server runs without sessions.',
        ], self::decode($response)['error']);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[TestDox('$method fails loud, as its effect would have to outlast the request')]
    #[DataProvider('provideSessionScopedRequests')]
    public function testSessionScopedRequestFailsLoud(string $method, array $params): void
    {
        $response = $this->send('POST', $this->request($method, $params));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'code' => -32603,
            'message' => \sprintf('"%s" needs a session, and this server runs without sessions.', $method),
        ], self::decode($response)['error']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideSessionScopedRequests(): iterable
    {
        yield 'resources/subscribe' => ['resources/subscribe', ['uri' => 'config://app']];
        yield 'resources/unsubscribe' => ['resources/unsubscribe', ['uri' => 'config://app']];
        yield 'logging/setLevel' => ['logging/setLevel', ['level' => 'debug']];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[TestDox('the protocol version comes from the header ($expected), as no session kept the negotiated one')]
    #[DataProvider('provideProtocolVersionHeaders')]
    public function testProtocolVersionComesFromTheHeader(array $headers, string $expected): void
    {
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'protocol-version', 'arguments' => new \stdClass()]), $headers);

        $this->assertSame($expected, self::decode($response)['result']['content'][0]['text']);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function provideProtocolVersionHeaders(): iterable
    {
        yield 'named' => [[StreamableHttpTransport::PROTOCOL_VERSION_HEADER => ProtocolVersion::V2025_06_18->value], ProtocolVersion::V2025_06_18->value];
        yield 'absent' => [[StreamableHttpTransport::PROTOCOL_VERSION_HEADER => null], ProtocolVersion::DEFAULT_HEADER_VERSION->value];
    }

    #[TestDox('a client capability check fails loud, as the capabilities the client declared on initialize are not known to later requests')]
    public function testClientCapabilityCheckFailsLoud(): void
    {
        $this->send('POST', $this->initialize());

        $response = $this->send('POST', $this->request('tools/call', ['name' => 'supports-elicitation', 'arguments' => new \stdClass()]));

        $this->assertSame('The client capabilities need a session; configure one with Builder::setSession().', self::decode($response)['result']['content'][0]['text']);
    }

    #[TestDox('what a handler sets on the session is gone on the next request, and saving it fails loud')]
    public function testSessionDataDoesNotOutliveTheRequest(): void
    {
        $remember = $this->send('POST', $this->request('tools/call', ['name' => 'remember', 'arguments' => new \stdClass()]));
        $recall = $this->send('POST', $this->request('tools/call', ['name' => 'recall', 'arguments' => new \stdClass()]));
        $save = $this->send('POST', $this->request('tools/call', ['name' => 'save', 'arguments' => new \stdClass()]));

        $this->assertSame('remembered', self::decode($remember)['result']['content'][0]['text']);
        $this->assertSame('forgotten', self::decode($recall)['result']['content'][0]['text']);
        $this->assertSame([
            'code' => -32603,
            'message' => 'Nothing in a session outlives its request without a session store; configure one with Builder::setSession().',
        ], self::decode($save)['error']);
    }

    #[TestDox('the modern era is served as before')]
    public function testModernEraIsServedAsBefore(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => [
                RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
            ]],
        ], \JSON_THROW_ON_ERROR);

        $response = $this->send('POST', $body, [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'server/discover',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([ProtocolVersion::V2026_07_28->value], self::decode($response)['result']['supportedVersions']);
    }

    private function server(): Server
    {
        return Server::builder()
            ->setServerInfo('sessionless', '1.0.0')
            ->addTool(static fn (string $text): string => $text, 'echo')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->notify(new ProgressNotification('p', 0.5));
                $context->getClientGateway()->notify(new ProgressNotification('p', 1.0));

                return 'done';
            }, 'notify')
            ->addTool(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->elicit('Name?', self::schema(), 5)), 'elicit')
            ->addTool(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->elicitUrl('Sign in', 'https://example.com/consent', 5)), 'elicit-url')
            ->addTool(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->sample('Hi', timeout: 5)), 'sample')
            ->addTool(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->listRoots(5)), 'list-roots')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->notify(new ProgressNotification('p', 0.5));

                return self::answered($context->getClientGateway()->elicit('Name?', self::schema(), 5));
            }, 'notify-then-elicit')
            ->addTool(static fn (): InputRequiredResult => new InputRequiredResult(['name' => ElicitRequest::forForm('Name?', self::schema())]), 'ask-for-input')
            ->addTool(static fn (RequestContext $context): string => $context->getProtocolVersion()->value, 'protocol-version')
            ->addTool(static fn (RequestContext $context): string => self::refusal(static fn (): bool => $context->getClientGateway()->supportsElicitation()), 'supports-elicitation')
            ->addTool(static function (RequestContext $context): string {
                $context->getSession()->set('memo', 'remembered');

                return (string) $context->getSession()->get('memo');
            }, 'remember')
            ->addTool(static fn (RequestContext $context): string => (string) $context->getSession()->get('memo', 'forgotten'), 'recall')
            ->addTool(static function (RequestContext $context): string {
                $context->getSession()->set('memo', 'remembered');
                $context->getSession()->save();

                return 'saved';
            }, 'save')
            ->addResource(static fn (): string => '{}', 'config://app', 'app-config')
            ->addResource(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->elicit('Name?', self::schema(), 5)), 'config://ask', 'ask-resource')
            ->addPrompt(static fn (RequestContext $context): string => self::answered($context->getClientGateway()->elicit('Name?', self::schema(), 5)), 'ask')
            ->build();
    }

    private static function answered(mixed $answer): string
    {
        return 'answered';
    }

    /**
     * @param \Closure(): mixed $ask
     */
    private static function refusal(\Closure $ask): string
    {
        try {
            $ask();
        } catch (SessionRequiredException $e) {
            return $e->getMessage();
        }

        return 'answered';
    }

    private static function schema(): ElicitationSchema
    {
        return new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']);
    }

    private function initialize(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => ['elicitation' => new \stdClass(), 'sampling' => new \stdClass(), 'roots' => new \stdClass()],
                'clientInfo' => ['name' => 'handshake-client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|null> $headers null leaves the header out
     */
    private function send(string $method, string $body = '', array $headers = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        if ('POST' === $method && !str_contains($body, '"initialize"')) {
            $request = $request->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, ProtocolVersion::V2025_11_25->value);
        }

        foreach ($headers as $name => $value) {
            $request = null === $value ? $request->withoutHeader($name) : $request->withHeader($name, $value);
        }

        return $this->server()->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
    }

    /**
     * Reads an SSE response; the stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
     */
    private function readStream(ResponseInterface $response): string
    {
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $response->getBody()->getContents();
        } finally {
            ob_end_flush();
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
