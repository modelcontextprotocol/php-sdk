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

use Mcp\Client\Client;
use Mcp\Client\Exception\InvalidArgumentException;
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\JsonRpc\Error;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpTransportTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameProvider(): iterable
    {
        yield 'LF line endings' => ["event: message\ndata: %s\n\n"];
        // sse-starlette (and therefore every MCP Python SDK server) defaults to CRLF.
        yield 'CRLF line endings' => ["event: message\r\ndata: %s\r\n\r\n"];
        yield 'CR line endings' => ["event: message\rdata: %s\r\r"];
        yield 'with an id field' => ["id: 1\r\nevent: message\r\ndata: %s\r\n\r\n"];
        yield 'no trailing blank line' => ["event: message\ndata: %s\n"];
        yield 'preceded by a comment' => [": ping\n\nevent: message\ndata: %s\n\n"];
    }

    #[DataProvider('frameProvider')]
    #[TestDox('initialization succeeds for an SSE response framed as: $_dataName')]
    public function testInitializeParsesSseFraming(string $frame): void
    {
        $httpClient = new class($frame) implements ClientInterface {
            public function __construct(private readonly string $frame)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $decoded = json_decode((string) $request->getBody(), true);

                if ('initialize' !== ($decoded['method'] ?? null)) {
                    return new Response(202);
                }

                $payload = json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $decoded['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
                    ],
                ]);

                return new Response(200, [
                    'Content-Type' => 'text/event-stream',
                    'Mcp-Session-Id' => 'abc123',
                ], \sprintf($this->frame, $payload));
            }
        };

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setInitTimeout(1)
            ->build();

        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $this->assertTrue($client->isConnected());
        $this->assertSame('test-server', $client->getServerInfo()?->name);
    }

    #[TestDox('SSE stream is aborted before the buffer can exceed the configured cap')]
    public function testSseBufferIsBoundedByConfiguredCap(): void
    {
        $transport = $this->createTransport(maxSseBufferBytes: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        // A server that streams data without ever sending the "\n\n" delimiter.
        $this->setActiveStream($transport, $this->factory->createStream(str_repeat('a', 4096)));

        $this->invokeProcessSseStream($transport);

        $this->assertSame('', $this->readPrivate($transport, 'sseBuffer'), 'buffer must be cleared on abort');
        $this->assertNull($this->readPrivate($transport, 'activeStream'), 'stream must be released on abort');
    }

    #[TestDox('aborting the SSE stream fails the in-flight request immediately instead of waiting for its timeout')]
    public function testAbortFailsPendingRequestFast(): void
    {
        $transport = $this->createTransport(maxSseBufferBytes: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        $this->setActiveStream($transport, $this->factory->createStream(str_repeat('a', 4096)));

        $this->invokeProcessSseStream($transport);

        $response = $state->consumeResponse(1);
        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::INTERNAL_ERROR, $response->code);
        $this->assertSame(1, $response->id);
    }

    #[TestDox('well-formed delimited events are parsed and dispatched')]
    public function testWellFormedEventsStillParse(): void
    {
        $transport = $this->createTransport();
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->setActiveStream($transport, $this->factory->createStream("data: hello\n\ndata: world\n\n"));

        $this->invokeProcessSseStream($transport);

        $this->assertSame(['hello', 'world'], $messages);
    }

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(maxSseBufferBytes: 0);
    }

    private function createTransport(int $maxSseBufferBytes = 8 * 1024 * 1024): HttpTransport
    {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            httpClient: $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            maxSseBufferBytes: $maxSseBufferBytes,
        );
    }

    private function setActiveStream(HttpTransport $transport, StreamInterface $stream): void
    {
        (new \ReflectionProperty($transport, 'activeStream'))->setValue($transport, $stream);
    }

    private function invokeProcessSseStream(HttpTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'processSSEStream'))->invoke($transport);
    }

    private function readPrivate(HttpTransport $transport, string $property): mixed
    {
        return (new \ReflectionProperty($transport, $property))->getValue($transport);
    }
}
