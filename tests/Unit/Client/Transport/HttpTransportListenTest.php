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
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\ListRootsRequest;
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

    private function echo(Client $client, string $text): ?string
    {
        $content = $client->callTool('echo', ['text' => $text])->content[0] ?? null;

        return $content instanceof TextContent ? $content->text : null;
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
    ): Client {
        $factory = new Psr17Factory();

        $client = Client::builder()
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
            }))
            ->build();

        $client->connect(new HttpTransport('http://localhost/mcp', $headers, $server, $factory, $factory, maxSseBufferBytes: $maxSseBufferBytes, listen: $listen));

        return $client;
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

        // Streamed, so the client reads its streams in the meantime: a JSON
        // answer completes the call before the listening stream is looked at.
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
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
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
