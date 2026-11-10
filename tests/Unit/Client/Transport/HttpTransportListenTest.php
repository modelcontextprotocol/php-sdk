<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Transport;

use Mcp\Client;
use Mcp\Client\Auth\AuthenticatorInterface;
use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The standalone GET stream a 2025-era server sends unprompted messages on.
 *
 * Streams are socket pairs, so the fake server can hold one open with nothing
 * written yet — the case a blocking read would hang on.
 */
final class HttpTransportListenTest extends TestCase
{
    #[TestDox('a server request on the listening stream is answered while a tool call waits on its own stream')]
    public function testRequestOnListenStreamIsAnsweredDuringToolCall(): void
    {
        $server = new FakeListeningServer();
        $client = $this->client($server, listen: true);

        $result = $client->callTool('get-roots-list');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('roots: file:///workspace/app', $result->content[0]->text);

        $get = $server->requests('GET')[0] ?? null;
        $this->assertNotNull($get, 'the client opens the listening stream');
        $this->assertSame('text/event-stream', $get->getHeaderLine('Accept'));
        $this->assertSame('session-1', $get->getHeaderLine('Mcp-Session-Id'));
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $get->getHeaderLine('MCP-Protocol-Version'));

        $client->disconnect();
    }

    #[TestDox('no listening stream is opened unless asked for')]
    public function testNoListenStreamByDefault(): void
    {
        $server = new FakeListeningServer();
        $client = $this->client($server, listen: false);

        $this->assertSame([], $server->requests('GET'));

        $client->disconnect();
    }

    #[TestDox('the optional listening stream never starts a sign-in')]
    public function testListenStreamDoesNotHandleAChallenge(): void
    {
        $server = new FakeListeningServer(getStatus: 401);
        $factory = new Psr17Factory();
        $auth = $this->createMock(AuthenticatorInterface::class);
        $auth->method('authenticate')->willReturnArgument(0);
        $auth->expects($this->never())->method('handleChallenge');

        $client = Client::builder()->setClientInfo('test-client', '1.0.0')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setInitTimeout(2)->setRequestTimeout(2)->build();
        $client->connect(new HttpTransport('https://example.test/mcp', [], $server, $factory, $factory, auth: $auth, listen: true));

        $this->assertTrue($client->isConnected());
        $this->assertCount(1, $server->requests('GET'));

        $client->disconnect();
    }

    #[TestDox('a server without a listening stream (405) leaves the connection usable')]
    public function testServerWithoutListenStream(): void
    {
        $server = new FakeListeningServer(getStatus: 405);
        $client = $this->client($server, listen: true);

        $this->assertTrue($client->isConnected());
        $this->assertCount(1, $server->requests('GET'));

        $client->disconnect();
    }

    #[TestDox('nothing is opened on 2026-07-28, which has no standalone stream')]
    public function testNoListenStreamOnModernRevision(): void
    {
        $server = new FakeListeningServer();
        $client = $this->client($server, listen: true, version: ProtocolVersion::V2026_07_28);

        $this->assertTrue($client->isConnected());
        $this->assertSame([], $server->requests('GET'));

        $client->disconnect();
    }

    /** @return iterable<string, array{int, string}> */
    public static function unexpectedAnswerProvider(): iterable
    {
        yield 'unknown session (404)' => [404, 'application/json'];
        yield 'JSON instead of a stream' => [200, 'application/json'];
    }

    #[DataProvider('unexpectedAnswerProvider')]
    #[TestDox('a listening stream answered otherwise leaves the connection usable: $_dataName')]
    public function testUnexpectedAnswerToListenStream(int $status, string $contentType): void
    {
        $server = new FakeListeningServer(getStatus: $status, getContentType: $contentType);
        $client = $this->client($server, listen: true);

        $this->assertSame('hello', $this->echo($client, 'hello'));

        $client->disconnect();
    }

    #[TestDox('the GET carries the configured headers')]
    public function testListenStreamCarriesConfiguredHeaders(): void
    {
        $server = new FakeListeningServer();
        $client = $this->client($server, listen: true, headers: ['Authorization' => 'Bearer secret']);

        $this->assertSame('Bearer secret', $server->requests('GET')[0]->getHeaderLine('Authorization'));

        $client->disconnect();
    }

    #[TestDox('a message on the listening stream arrives during a request answered with plain JSON')]
    public function testListenStreamIsReadDuringPlainJsonRequest(): void
    {
        $received = [];
        $server = new FakeListeningServer(rootsOnGet: false);
        $client = $this->client($server, listen: true, onNotification: static function (string $method) use (&$received): void {
            $received[] = $method;
        });

        fwrite($server->getStream(), "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/tools/list_changed\"}\n\n");

        $client->callTool('echo-json');
        $this->assertSame(['notifications/tools/list_changed'], $received);

        $client->disconnect();
    }

    #[TestDox('a listening stream failing to read is closed, without losing the result of a request answered with plain JSON')]
    public function testListenStreamReadFailureKeepsTheResult(): void
    {
        $factory = new FailingReadStreamFactory();
        $client = $this->client(new FakeListeningServer(rootsOnGet: false), listen: true, streamFactory: $factory);

        $factory->failReads = true;
        $first = $this->firstText($client->callTool('echo-json'));
        $second = $this->firstText($client->callTool('echo-json'));

        $this->assertSame(['json', 'json'], [$first, $second]);

        $client->disconnect();
    }

    #[TestDox('a listening stream the server ends leaves the connection usable')]
    public function testListenStreamEndedByServer(): void
    {
        $server = new FakeListeningServer(rootsOnGet: false);
        $client = $this->client($server, listen: true);

        fclose($server->getStream());

        $this->assertSame('first', $this->echo($client, 'first'));
        $this->assertSame('second', $this->echo($client, 'second'));

        $client->disconnect();
    }

    #[TestDox('an event larger than one read that already arrived is handled within a call answered with JSON')]
    public function testEventLargerThanOneReadIsHandledWithinOneCall(): void
    {
        $server = new FakeListeningServer(rootsOnGet: false);
        $client = $this->client($server, listen: true);

        fwrite($server->getStream(), self::paddedRootsRequest('srv-1', 20_000));

        $this->assertSame('hello', $this->echo($client, 'hello', 'echo-json'));
        $this->assertSame(['srv-1'], $server->answeredIds());

        $client->disconnect();
    }

    #[TestDox('a call reads at most 64 KiB of the listening stream, leaving the rest for the next call')]
    public function testListenStreamReadIsBoundedPerCall(): void
    {
        $server = new FakeListeningServer(rootsOnGet: false);
        $client = $this->client($server, listen: true);

        foreach (['srv-1', 'srv-2', 'srv-3'] as $id) {
            fwrite($server->getStream(), self::paddedRootsRequest($id, 30_000));
        }

        $this->assertSame('first', $this->echo($client, 'first', 'echo-json'));
        $this->assertSame(['srv-1', 'srv-2'], $server->answeredIds());

        $this->assertSame('second', $this->echo($client, 'second', 'echo-json'));
        $this->assertSame(['srv-1', 'srv-2', 'srv-3'], $server->answeredIds());

        $client->disconnect();
    }

    #[TestDox('an oversized event on the listening stream closes it, leaving the connection usable')]
    public function testOversizedEventOnListenStreamClosesIt(): void
    {
        $server = new FakeListeningServer(rootsOnGet: false);
        $client = $this->client($server, listen: true, maxSseBufferBytes: 256);

        $serverEnd = $server->getStream();
        fwrite($serverEnd, 'data: '.str_repeat('x', 1024));

        $this->assertSame('hello', $this->echo($client, 'hello'));

        stream_set_blocking($serverEnd, false);
        fread($serverEnd, 1);
        $this->assertTrue(feof($serverEnd), 'the client closed its end of the listening stream');

        $client->disconnect();
    }

    private function firstText(CallToolResult $result): ?string
    {
        $content = $result->content[0] ?? null;

        return $content instanceof TextContent ? $content->text : null;
    }

    private function echo(Client $client, string $text, string $tool = 'echo'): ?string
    {
        $content = $client->callTool($tool, ['text' => $text])->content[0] ?? null;

        return $content instanceof TextContent ? $content->text : null;
    }

    private static function paddedRootsRequest(string $id, int $padding): string
    {
        return 'data: '.json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'roots/list',
            'params' => ['_meta' => ['padding' => str_repeat('x', $padding)]],
        ])."\n\n";
    }

    /**
     * @param array<string, string> $headers
     */
    private function client(
        FakeListeningServer $server,
        bool $listen,
        ProtocolVersion $version = ProtocolVersion::V2025_11_25,
        array $headers = [],
        int $maxSseBufferBytes = 1_048_576,
        ?callable $onNotification = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): Client {
        $factory = new Psr17Factory();

        $builder = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion($version)
            ->setInitTimeout(2)
            ->setRequestTimeout(2)
            ->setCapabilities(new ClientCapabilities(roots: true))
            ->addRequestHandler(new ListRootsRequestHandler(new class implements RootsCallbackInterface {
                public function __invoke(ListRootsRequest $request): ListRootsResult
                {
                    return new ListRootsResult([new Root('file:///workspace/app', 'Application')]);
                }
            }));

        if (null !== $onNotification) {
            $builder->addNotificationHandler(new class($onNotification) implements NotificationHandlerInterface {
                public function __construct(private readonly mixed $callback)
                {
                }

                public function supports(Notification $notification): bool
                {
                    return true;
                }

                public function handle(Notification $notification): void
                {
                    ($this->callback)($notification::getMethod());
                }
            });
        }

        $client = $builder->build();

        $client->connect(new HttpTransport('http://localhost/mcp', $headers, $server, $factory, $streamFactory ?? $factory, maxSseBufferBytes: $maxSseBufferBytes, listen: $listen));

        return $client;
    }
}

/**
 * Hands out streams whose reads fail once `failReads` is set, like a connection reset.
 */
final class FailingReadStreamFactory implements StreamFactoryInterface
{
    public bool $failReads = false;

    private readonly Psr17Factory $inner;

    public function __construct()
    {
        $this->inner = new Psr17Factory();
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->inner->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->inner->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new FailingReadStream($this->inner->createStreamFromResource($resource), $this);
    }
}

final class FailingReadStream implements StreamInterface
{
    public function __construct(
        private readonly StreamInterface $inner,
        private readonly FailingReadStreamFactory $factory,
    ) {
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        if ($this->factory->failReads) {
            throw new \RuntimeException('Unable to read from stream: connection reset by peer');
        }

        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}

/**
 * Answers `get-roots-list` the way the TypeScript reference server does: it asks
 * for the roots on the listening stream, and finishes the tool call on the call's
 * own stream once the client has answered.
 */
final class FakeListeningServer implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var resource|null the server end of the tool call's stream */
    private $callStream;

    private int|string|null $callId = null;

    /** @var resource|null the server end of the listening stream */
    private $getStream;

    public function __construct(
        private readonly int $getStatus = 200,
        private readonly string $getContentType = 'text/event-stream',
        private readonly bool $rootsOnGet = true,
    ) {
    }

    /**
     * @return resource the server end of the listening stream
     */
    public function getStream()
    {
        return $this->getStream ?? throw new \RuntimeException('No listening stream was opened.');
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(string $method): array
    {
        return array_values(array_filter($this->requests, static fn (RequestInterface $r): bool => $method === $r->getMethod()));
    }

    /**
     * @return list<string> the ids of the server requests the client answered
     */
    public function answeredIds(): array
    {
        $ids = [];
        foreach ($this->requests('POST') as $request) {
            $message = json_decode((string) $request->getBody(), true);
            if (\is_string($message['id'] ?? null) && str_starts_with($message['id'], 'srv-') && \array_key_exists('result', $message)) {
                $ids[] = $message['id'];
            }
        }

        return $ids;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ('GET' === $request->getMethod()) {
            if (200 !== $this->getStatus || 'text/event-stream' !== $this->getContentType) {
                return new Response($this->getStatus, ['Content-Type' => $this->getContentType], '{}');
            }

            [$this->getStream, $client] = $this->socketPair();
            if ($this->rootsOnGet) {
                fwrite($this->getStream, self::event(['jsonrpc' => '2.0', 'id' => 'srv-1', 'method' => 'roots/list']));
            }

            return new Response(200, ['Content-Type' => 'text/event-stream'], Stream::create($client));
        }

        if ('DELETE' === $request->getMethod()) {
            return new Response(200);
        }

        $message = json_decode((string) $request->getBody(), true);

        if ('initialize' === ($message['method'] ?? null)) {
            return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'session-1'], json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
                ],
            ], \JSON_THROW_ON_ERROR));
        }

        if ('server/discover' === ($message['method'] ?? null)) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => [
                    'resultType' => 'complete',
                    'supportedVersions' => ['2026-07-28'],
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
                ],
            ], \JSON_THROW_ON_ERROR));
        }

        if ('tools/call' === ($message['method'] ?? null) && 'echo-json' === ($message['params']['name'] ?? null)) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => ['content' => [['type' => 'text', 'text' => $message['params']['arguments']['text'] ?? 'json']]],
            ], \JSON_THROW_ON_ERROR));
        }

        if ('tools/call' === ($message['method'] ?? null) && 'echo' === ($message['params']['name'] ?? null)) {
            return new Response(200, ['Content-Type' => 'text/event-stream'], self::event([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => ['content' => [['type' => 'text', 'text' => $message['params']['arguments']['text'] ?? '']]],
            ]));
        }

        if ('tools/call' === ($message['method'] ?? null)) {
            // Held open with nothing on it until the roots arrive.
            [$this->callStream, $client] = $this->socketPair();
            $this->callId = $message['id'];

            return new Response(200, ['Content-Type' => 'text/event-stream'], Stream::create($client));
        }

        if ('srv-1' === ($message['id'] ?? null) && null !== $this->callStream) {
            $uri = $message['result']['roots'][0]['uri'] ?? '?';
            fwrite($this->callStream, self::event([
                'jsonrpc' => '2.0',
                'id' => $this->callId,
                'result' => ['content' => [['type' => 'text', 'text' => 'roots: '.$uri]]],
            ]));
            fclose($this->callStream);
            $this->callStream = null;
        }

        return new Response(202);
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair('Windows' === \PHP_OS_FAMILY ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new \RuntimeException('Could not create a socket pair.');
        }

        return [$pair[0], $pair[1]];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function event(array $message): string
    {
        return 'data: '.json_encode($message)."\n\n";
    }
}
