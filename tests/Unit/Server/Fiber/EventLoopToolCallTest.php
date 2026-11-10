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
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\RequestMeta;
use Mcp\Server\Event\ErrorEvent;
use Mcp\Server\Event\ResponseEvent;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Server;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

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

        // The call failed, but the loop still resumes the tool.
        $this->loop->drain();
        $this->assertTrue($this->tookTheResult);
    }

    #[TestDox('without a scheduler, a tool awaiting I/O after a notification still answers over the open stream')]
    public function testWithoutASchedulerTheStreamEndsWithAnError(): void
    {
        $output = $this->stream($this->notifyingServer(scheduled: false), 2);

        $this->assertStringContainsString('notifications\/tools\/list_changed', $output);
        $this->assertMatchesRegularExpression('/"id":2,"error":\{"code":-32603/', $output);
    }

    #[TestDox('without a scheduler in the 2026-07-28 era, a tool awaiting I/O after a notification still ends its stream with an error')]
    public function testWithoutASchedulerTheModernStreamEndsWithAnError(): void
    {
        $version = ProtocolVersion::V2026_07_28->value;
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $this->post($this->notifyingServer(scheduled: false), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'lookup', 'arguments' => new \stdClass(), '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => $version,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ]],
            ], ['MCP-Protocol-Version' => $version, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'lookup']);
        } finally {
            ob_end_flush();
        }

        $this->assertStringContainsString('notifications/tools/list_changed', $output);
        $this->assertMatchesRegularExpression('/"id":1,"error":\{"code":-32603/', $output);
    }

    #[TestDox('a handler finishing after its request was answered fires no events and is logged')]
    public function testAHandlerOutlivingItsRequestFiresNoEvents(): void
    {
        $logger = new RecordingLogger();
        $events = [];
        $dispatcher = new class($events) implements EventDispatcherInterface {
            /** @param list<string> $events */
            public function __construct(public array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event::class;

                return $event;
            }
        };
        $loop = $this->loop;
        $server = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setLogger($logger)
            ->setEventDispatcher($dispatcher)
            ->addTool(static fn (): string => 'lookup:'.$loop->await('found'), name: 'lookup', description: 'Looks something up')
            ->build();

        $answer = $this->call($server, false);
        $this->assertSame(-32603, $answer['error']['code'] ?? null);
        $this->assertContains(ErrorEvent::class, $events);

        $events = [];
        $this->loop->drain();

        $this->assertSame([], $events);
        $this->assertContains('A request handler finished after its request was answered.', $logger->messages);
    }

    #[DataProvider('eras')]
    #[TestDox('what a handler throws after its request was answered is logged')]
    public function testAHandlerThrowingAfterItsRequestIsLogged(bool $modern): void
    {
        $logger = new RecordingLogger();
        $loop = $this->loop;
        $server = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setLogger($logger)
            ->addRequestHandler(new class($loop) implements RequestHandlerInterface {
                public function __construct(private readonly FakeEventLoop $loop)
                {
                }

                public function supports(Request $request): bool
                {
                    return $request instanceof CallToolRequest;
                }

                public function handle(Request $request, SessionInterface $session): Response|Error
                {
                    $this->loop->await(null);

                    throw new \DomainException('The lookup failed.');
                }
            })
            ->addTool(static fn (): string => 'unused', name: 'lookup', description: 'Looks something up')
            ->build();

        $answer = $this->call($server, $modern);
        $this->assertSame(-32603, $answer['error']['code'] ?? null);

        $logger->messages = [];
        $this->loop->drain();

        $this->assertSame(['A request handler failed after its request was answered: The lookup failed.'], $logger->messages);
    }

    #[TestDox('what a response listener throws after the request was answered is logged and fires no event')]
    public function testAResponseListenerThrowingAfterTheRequestIsLogged(): void
    {
        $logger = new RecordingLogger();
        $events = [];
        $loop = $this->loop;
        $dispatcher = new class($events, $loop) implements EventDispatcherInterface {
            /** @param list<string> $events */
            public function __construct(public array &$events, private readonly FakeEventLoop $loop)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event::class;

                if ($event instanceof ResponseEvent && $event->getRequest() instanceof CallToolRequest) {
                    $this->loop->await(null);

                    throw new \DomainException('The listener failed.');
                }

                return $event;
            }
        };
        $server = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setLogger($logger)
            ->setEventDispatcher($dispatcher)
            ->addTool(static fn (): string => 'found', name: 'lookup', description: 'Looks something up')
            ->build();

        $answer = $this->call($server, false);
        $this->assertSame(-32603, $answer['error']['code'] ?? null);

        $events = [];
        $logger->messages = [];
        $this->loop->drain();

        $this->assertSame([], $events);
        $this->assertSame(['A request handler failed after its request was answered: The listener failed.'], $logger->messages);
    }

    #[TestDox('a gateway call after the request was answered throws instead of leaving the handler suspended')]
    public function testAGatewayCallOfAnAbandonedHandlerIsLogged(): void
    {
        $logger = new RecordingLogger();
        $loop = $this->loop;
        $server = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setLogger($logger)
            ->addTool(static function (RequestContext $context) use ($loop): string {
                $loop->await(null);
                $context->getClientGateway()->notify(new ToolListChangedNotification());

                return 'unreachable';
            }, name: 'lookup', description: 'Looks something up')
            ->build();

        $this->call($server, false);
        $logger->messages = [];
        $this->loop->drain();

        $this->assertContains('A request handler finished after its request was answered.', $logger->messages);
    }

    #[TestDox('a tool awaiting I/O around a notification streams the notification and its result')]
    public function testAToolAwaitingIoAroundANotificationStreamsBoth(): void
    {
        $output = $this->stream($this->notifyingServer(scheduled: true), 2);

        $this->assertStringContainsString('notifications\/tools\/list_changed', $output);
        $this->assertMatchesRegularExpression('/"id":2,"result".*lookup:found/', $output);
    }

    #[TestDox('without a scheduler over stdio, a tool awaiting I/O after a notification fails its call and the server goes on')]
    public function testWithoutASchedulerStdioFailsTheCallAndGoesOn(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'event-loop-client', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'lookup', 'arguments' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'],
        ];

        $input = fopen('php://temp', 'r+');
        $this->assertIsResource($input);
        fwrite($input, implode("\n", array_map(static fn (array $message): string => json_encode($message, \JSON_THROW_ON_ERROR), $messages))."\n");
        rewind($input);

        $path = tempnam(sys_get_temp_dir(), 'mcp');
        $this->assertIsString($path);
        $output = fopen($path, 'w');
        $this->assertIsResource($output);

        try {
            $this->notifyingServer(scheduled: false)->run(new StdioTransport($input, $output));
            $written = (string) file_get_contents($path);
        } finally {
            unlink($path);
        }

        $this->assertMatchesRegularExpression('/"id":2,"error":\{"code":-32603/', $written);
        $this->assertMatchesRegularExpression('/"id":3,"result"/', $written);
    }

    private function notifyingServer(bool $scheduled): Server
    {
        $loop = $this->loop;
        $builder = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->addTool(static function (RequestContext $context) use ($loop): string {
                $context->getClientGateway()->notify(new ToolListChangedNotification());

                return 'lookup:'.$loop->await('found');
            }, name: 'lookup', description: 'Looks something up');

        if ($scheduled) {
            $builder->setFiberScheduler($loop);
        }

        return $builder->build();
    }

    private function stream(Server $server, int $id): string
    {
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

        // The stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $this->post($server, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'lookup', 'arguments' => new \stdClass()],
            ], $headers);
        } finally {
            ob_end_flush();
        }

        return $output;
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
