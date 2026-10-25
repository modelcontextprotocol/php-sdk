<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Fiber;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A tool awaiting I/O under an event loop, over HTTP, in both protocol eras.
 *
 * The loop suspends the handler's fiber while the I/O is in flight: the server waits on it through the
 * fiber scheduler and answers with what the tool returns. Without a scheduler the call fails, where the
 * server used to resume the fiber itself with nothing — the tool's await returning early, the loop later
 * resuming a fiber that had moved on.
 */
final class EventLoopToolCallTest extends TestCase
{
    private Psr17Factory $factory;

    private FakeEventLoop $loop;

    private bool $tookTheResult = false;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->loop = new FakeEventLoop();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function eras(): iterable
    {
        yield 'the 2026-07-28 era' => [true];
        yield 'the handshake era' => [false];
    }

    #[DataProvider('eras')]
    #[TestDox('a tool awaiting I/O answers with what it awaited, the server waiting on the loop')]
    public function testAToolAwaitingIoAnswersWithWhatItAwaited(bool $modern): void
    {
        $answer = $this->call($this->server(scheduled: true), $modern);

        $this->assertSame('lookup:found', $answer['result']['content'][0]['text'] ?? null);
        $this->assertTrue($this->tookTheResult);
        $this->assertSame(1, $this->loop->waits);
    }

    #[DataProvider('eras')]
    #[TestDox('without a scheduler, a tool awaiting I/O fails the call instead of being resumed with nothing')]
    public function testWithoutASchedulerTheCallFails(bool $modern): void
    {
        $answer = $this->call($this->server(scheduled: false), $modern);

        $this->assertSame(-32603, $answer['error']['code'] ?? null);
        $this->assertFalse($this->tookTheResult);
    }

    private function server(bool $scheduled): Server
    {
        $loop = $this->loop;
        $tookTheResult = &$this->tookTheResult;
        $builder = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->addTool(static function () use ($loop, &$tookTheResult): string {
                $found = $loop->await('found');
                $tookTheResult = true;

                return 'lookup:'.$found;
            }, name: 'lookup', description: 'Looks something up');

        if ($scheduled) {
            $builder->setFiberScheduler($loop);
        }

        return $builder->build();
    }

    /**
     * @return array<string, mixed> the JSON-RPC answer to a tools/call of the lookup tool
     */
    private function call(Server $server, bool $modern): array
    {
        if ($modern) {
            return $this->post($server, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'lookup', 'arguments' => new \stdClass(), '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ]],
            ], [
                'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'lookup',
            ])['body'];
        }

        $session = $this->post($server, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'event-loop-client', 'version' => '1.0.0'],
            ],
        ])['session'];
        $headers = ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => ProtocolVersion::V2025_11_25->value];
        $this->post($server, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        return $this->post($server, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup', 'arguments' => new \stdClass()],
        ], $headers)['body'];
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     *
     * @return array{session: string, body: array<string, mixed>}
     */
    private function post(Server $server, array $message, array $headers = []): array
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream(json_encode($message, \JSON_THROW_ON_ERROR)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
        $payload = (string) $response->getBody();
        $body = '' === $payload ? [] : json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        return [
            'session' => $response->getHeaderLine('Mcp-Session-Id'),
            'body' => \is_array($body) ? $body : [],
        ];
    }
}
