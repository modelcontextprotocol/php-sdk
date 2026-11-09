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

use Mcp\Example\Server\ReactPhp\ReactFiberScheduler;
use Mcp\Example\Server\Revolt\RevoltFiberScheduler;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Tests\Unit\Fixtures\RecordingFiberContext;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use Revolt\EventLoop;

use function Amp\delay;

/**
 * The fiber schedulers of the examples on their real event loops, where a fiber the loop resumes
 * suspends back to the loop, and where requests overlap.
 */
final class RealEventLoopTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @return iterable<string, array{FiberSchedulerInterface, \Closure(float): void, \Closure(list<\Closure(): void>): void}>
     */
    public static function runtimes(): iterable
    {
        yield 'Revolt' => [
            new RevoltFiberScheduler(),
            static function (float $seconds): void {
                delay($seconds);
            },
            static function (array $tasks): void {
                foreach ($tasks as $task) {
                    EventLoop::queue($task);
                }
                EventLoop::run();
            },
        ];

        yield 'ReactPHP' => [
            new ReactFiberScheduler(),
            static function (float $seconds): void {
                \React\Async\delay($seconds);
            },
            static function (array $tasks): void {
                foreach ($tasks as $task) {
                    (new \Fiber($task))->start();
                }
                Loop::run();
            },
        ];
    }

    /**
     * @return iterable<string, array{FiberSchedulerInterface, \Closure(float): void, \Closure(list<\Closure(): void>): void, bool}>
     */
    public static function runtimesWithAndWithoutSessions(): iterable
    {
        foreach (self::runtimes() as $runtime => $arguments) {
            yield "$runtime, on a session" => [...$arguments, true];
            yield "$runtime, sessionless" => [...$arguments, false];
        }
    }

    /**
     * @return iterable<string, array{FiberSchedulerInterface, \Closure(float): void, \Closure(list<\Closure(): void>): void, bool}>
     */
    public static function runtimesWithAndWithoutASessionLock(): iterable
    {
        foreach (self::runtimes() as $runtime => $arguments) {
            yield "$runtime, without a session lock" => [...$arguments, false];
            yield "$runtime, with a session lock" => [...$arguments, true];
        }
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('a quick request is answered while a slow one awaits I/O')]
    public function testRequestsOverlap(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->builder($scheduler)
            ->addTool(static function () use ($sleep): string {
                $sleep(0.05);

                return 'slow done';
            }, name: 'slow', description: 'Answers late')
            ->addTool(static fn (): string => 'quick done', name: 'quick', description: 'Answers right away')
            ->build();

        $answers = [];
        $run([
            function () use ($server, &$answers): void {
                $answers[] = $this->text($this->modernCall($server, 'slow'));
            },
            function () use ($server, &$answers): void {
                $answers[] = $this->text($this->modernCall($server, 'quick'));
            },
        ]);

        $this->assertSame(['quick done', 'slow done'], $answers);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('an elicitation between two awaits asks, then completes on the retry')]
    public function testElicitationRetry(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->builder($scheduler)
            ->setRequestState(str_repeat('event-loop-test-key-', 2))
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $sleep(0.01);
                $answer = $context->getClientGateway()->elicit('Your name?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']), key: 'who');
                $sleep(0.01);

                return 'Hello, '.($answer->content['name'] ?? 'nobody');
            }, name: 'greet', description: 'Greets you')
            ->build();

        $asked = $done = null;
        $run([
            function () use ($server, &$asked, &$done): void {
                $capabilities = ['elicitation' => new \stdClass()];
                $asked = $this->decode($this->modernCall($server, 'greet', $capabilities))['result'] ?? [];
                $done = $this->text($this->modernCall($server, 'greet', $capabilities, [
                    'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
                ]));
            },
        ]);

        $this->assertSame('input_required', $asked['resultType'] ?? null);
        $this->assertSame('Hello, Ada', $done);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('a notification between two awaits is streamed ahead of the result')]
    public function testNotificationBetweenAwaits(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->builder($scheduler)
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $sleep(0.01);
                $context->getClientGateway()->notify(new ToolListChangedNotification());
                $sleep(0.01);

                return 'notified';
            }, name: 'notifier', description: 'Sends a notification')
            ->build();

        // The stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $run([
                function () use ($server): void {
                    (string) $this->modernCall($server, 'notifier')->getBody();
                },
            ]);
        } finally {
            ob_end_flush();
        }

        $this->assertMatchesRegularExpression('/list_changed.*"id":1,"result".*notified/s', $output);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('what a tool throws after an await fails the call and stays out of the loop')]
    public function testThrowAfterAwait(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->builder($scheduler)
            ->addTool(static function () use ($sleep): never {
                $sleep(0.01);

                throw new \DomainException('The lookup failed.');
            }, name: 'failing', description: 'Always fails')
            ->build();

        $answer = null;
        $run([
            function () use ($server, &$answer): void {
                $answer = $this->decode($this->modernCall($server, 'failing'));
            },
        ]);

        $this->assertSame(-32603, $answer['error']['code'] ?? null);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('overlapping requests keep their own fiber context across awaits')]
    public function testOverlappingRequestsKeepTheirFiberContext(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $context = new RecordingFiberContext();
        $server = $this->builder($scheduler)
            ->setFiberContext($context)
            ->addTool(static function () use ($context, $sleep): string {
                $before = $context->current();
                $sleep(0.02);

                return $before.':'.$context->current();
            }, name: 'probe', description: 'Reads the context around an await')
            ->build();

        $answers = [];
        $run([
            function () use ($server, $context, &$answers): void {
                $context->parent = 'first';
                $answers[] = $this->text($this->modernCall($server, 'probe'));
            },
            function () use ($server, $context, &$answers): void {
                $context->parent = 'second';
                $answers[] = $this->text($this->modernCall($server, 'probe'));
            },
        ]);

        $this->assertSame(['first:first', 'second:second'], $answers);
        $this->assertSame(2, $context->released);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('a request of a locked session waits on the loop until the request holding the lock is done')]
    public function testARequestWaitsForTheSessionLock(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->lockedBuilder($scheduler)
            ->addTool(static function () use ($sleep): string {
                $sleep(0.05);

                return 'slow done';
            }, name: 'slow', description: 'Answers late')
            ->addTool(static fn (): string => 'quick done', name: 'quick', description: 'Answers right away')
            ->build();

        $answers = [];
        $headers = null;
        $run([
            function () use ($server, &$headers, &$answers): void {
                $headers = $this->handshake($server);
                $answers[] = $this->text($this->handshakeCall($server, $headers, 'slow', 3));
            },
            function () use ($server, &$headers, &$answers, $sleep): void {
                while (null === $headers) {
                    $sleep(0.001);
                }
                $sleep(0.01);
                $answers[] = $this->text($this->handshakeCall($server, $headers, 'quick', 4));
            },
        ]);

        $this->assertSame(['slow done', 'quick done'], $answers);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('a resumed request keeps its session write while another request of the session holds the lock')]
    public function testAResumedRequestKeepsItsSessionWrite(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->lockedBuilder($scheduler)
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $context->getClientGateway()->notify(new ToolListChangedNotification());
                $sleep(0.03);
                $context->getSession()->set('written', true);

                return 'writer done';
            }, name: 'writer', description: 'Writes to the session after a notification')
            ->addTool(static function () use ($sleep): string {
                $sleep(0.1);

                return 'holder done';
            }, name: 'holder', description: 'Holds the session lock')
            ->addTool(static fn (RequestContext $context): string => json_encode($context->getSession()->get('written'), \JSON_THROW_ON_ERROR), name: 'reader', description: 'Reads the session')
            ->build();

        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });

        $answers = [];
        $headers = null;
        try {
            $run([
                function () use ($server, &$headers): void {
                    $headers = $this->handshake($server);
                    (string) $this->handshakeCall($server, $headers, 'writer', 2)->getBody();
                },
                function () use ($server, &$headers, &$answers, $sleep): void {
                    while (null === $headers) {
                        $sleep(0.001);
                    }
                    $sleep(0.01);
                    $answers[] = $this->text($this->handshakeCall($server, $headers, 'holder', 3));
                    $sleep(0.05);
                    $answers[] = $this->text($this->handshakeCall($server, $headers, 'reader', 4));
                },
            ]);
        } finally {
            ob_end_flush();
        }

        $this->assertSame(['holder done', 'true'], $answers);
        $this->assertMatchesRegularExpression('/"id":2,"result".*writer done/', $output);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('a runtime writing the body itself streams a notification ahead of the result')]
    public function testChunksStreamANotificationAheadOfTheResult(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $server = $this->builder($scheduler)
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $sleep(0.01);
                $context->getClientGateway()->notify(new ToolListChangedNotification());
                $sleep(0.01);

                return 'notified';
            }, name: 'notifier', description: 'Sends a notification')
            ->build();

        $written = '';
        $run([
            function () use ($server, &$written): void {
                $body = $this->modernCall($server, 'notifier')->getBody();
                $this->assertInstanceOf(CallbackStream::class, $body);

                foreach ($body->chunks() ?? [] as $chunk) {
                    $written .= $chunk;
                }
            },
        ]);

        $this->assertMatchesRegularExpression('/^data: .*list_changed.*\n\ndata: .*"id":1,"result".*notified.*\n\n$/s', $written);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('dropping the chunks when the client goes away stops the handler before it sends anything more')]
    public function testDroppingTheChunksStopsTheHandler(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $unwound = false;
        $finished = false;
        $server = $this->builder($scheduler)
            ->addTool(static function (RequestContext $context) use ($sleep, &$unwound, &$finished): string {
                try {
                    $sleep(0.01);
                    $context->getClientGateway()->notify(new ToolListChangedNotification());
                    $finished = true;

                    return 'notified';
                } finally {
                    $unwound = true;
                }
            }, name: 'notifier', description: 'Sends a notification')
            ->build();

        $written = [];
        $unwoundWhileTheBodyIsHeld = false;
        $run([
            function () use ($server, &$written, &$unwound, &$unwoundWhileTheBodyIsHeld): void {
                $body = $this->modernCall($server, 'notifier')->getBody();
                $this->assertInstanceOf(CallbackStream::class, $body);
                $chunks = $body->chunks();
                $this->assertNotNull($chunks);

                $written[] = $chunks->current();
                unset($chunks);
                $unwoundWhileTheBodyIsHeld = $unwound;
                unset($body);
            },
        ]);

        $this->assertCount(1, $written);
        $this->assertStringContainsString('list_changed', $written[0]);
        $this->assertTrue($unwoundWhileTheBodyIsHeld, 'Dropping the chunks unwinds the handler while the body is still held.');
        $this->assertFalse($finished);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimesWithAndWithoutSessions')]
    #[TestDox('a runtime writing the body itself streams a handshake-era notification ahead of the result')]
    public function testChunksStreamAHandshakeEraNotification(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run, bool $sessions): void
    {
        $server = $this->builder($scheduler, $sessions)
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $sleep(0.01);
                $context->getClientGateway()->notify(new ToolListChangedNotification());
                $sleep(0.01);

                return 'notified';
            }, name: 'notifier', description: 'Sends a notification')
            ->build();

        $written = '';
        $run([
            function () use ($server, $sleep, &$written): void {
                $body = $this->handshakeCall($server, $this->handshake($server), 'notifier', 2)->getBody();
                $this->assertInstanceOf(CallbackStream::class, $body);

                foreach ($body->chunks() ?? [] as $chunk) {
                    '' === $chunk ? $sleep(0.01) : $written .= $chunk;
                }
            },
        ]);

        $this->assertMatchesRegularExpression('/^event: message\ndata: [^\n]*list_changed[^\n]*\n\nevent: message\ndata: [^\n]*"id":2,"result"[^\n]*notified[^\n]*\n\n$/', $written);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimesWithAndWithoutASessionLock')]
    #[TestDox('a handshake-era handler waiting on the client gets the answer of another request served by the same loop')]
    public function testAHandshakeEraElicitationIsAnsweredOnTheSameLoop(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run, bool $locked): void
    {
        $server = ($locked ? $this->lockedBuilder($scheduler) : $this->builder($scheduler))
            ->addTool(static function (RequestContext $context) use ($sleep): string {
                $sleep(0.01);
                $answer = $context->getClientGateway()->elicit('name?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));
                $sleep(0.01);

                return 'hello '.($answer->content['n'] ?? '?');
            }, name: 'asks', description: 'Asks the client for a name')
            ->build();

        $headers = $this->handshake($server, ['elicitation' => new \stdClass()]);

        $asked = null;
        $written = '';
        $run([
            function () use ($server, $headers, $sleep, &$asked, &$written): void {
                $body = $this->handshakeCall($server, $headers, 'asks', 2)->getBody();
                $this->assertInstanceOf(CallbackStream::class, $body);

                foreach ($body->chunks() ?? [] as $chunk) {
                    if ('' === $chunk) {
                        $sleep(0.01);

                        continue;
                    }

                    $written .= $chunk;
                    $message = json_decode(substr(explode("\n", $chunk)[1], 6), true, 512, \JSON_THROW_ON_ERROR);
                    if ('elicitation/create' === ($message['method'] ?? null)) {
                        $asked = $message['id'];
                    }
                }
            },
            function () use ($server, $headers, $sleep, &$asked): void {
                for ($turn = 0; null === $asked && $turn < 300; ++$turn) {
                    $sleep(0.01);
                }

                if (null === $asked) {
                    return;
                }

                $this->post($server, ['jsonrpc' => '2.0', 'id' => $asked, 'result' => ['action' => 'accept', 'content' => ['n' => 'Ada']]], $headers);
            },
        ]);

        $this->assertNotNull($asked);
        $this->assertMatchesRegularExpression('/"id":2,"result"[^\n]*hello Ada/', $written);
    }

    /**
     * @param \Closure(float): void                  $sleep
     * @param \Closure(list<\Closure(): void>): void $run
     */
    #[DataProvider('runtimes')]
    #[TestDox('dropping the chunks of a handshake-era handler waiting on the client stops the handler')]
    public function testDroppingTheChunksStopsAHandshakeEraHandlerWaitingOnTheClient(FiberSchedulerInterface $scheduler, \Closure $sleep, \Closure $run): void
    {
        $unwound = false;
        $answered = false;
        $server = $this->builder($scheduler)
            ->addTool(static function (RequestContext $context) use (&$unwound, &$answered): string {
                try {
                    $context->getClientGateway()->elicit('name?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));
                    $answered = true;

                    return 'answered';
                } finally {
                    $unwound = true;
                }
            }, name: 'asks', description: 'Asks the client for a name')
            ->build();
        $headers = $this->handshake($server, ['elicitation' => new \stdClass()]);

        $written = [];
        $unwoundWhileTheBodyIsHeld = false;
        $run([
            function () use ($server, $headers, &$written, &$unwound, &$unwoundWhileTheBodyIsHeld): void {
                $body = $this->handshakeCall($server, $headers, 'asks', 2)->getBody();
                $this->assertInstanceOf(CallbackStream::class, $body);
                $chunks = $body->chunks();
                $this->assertNotNull($chunks);

                $written[] = $chunks->current();
                unset($chunks);
                $unwoundWhileTheBodyIsHeld = $unwound;
                unset($body);
            },
        ]);

        $this->assertCount(1, $written);
        $this->assertStringContainsString('"method":"elicitation\\/create"', $written[0]);
        $this->assertTrue($unwoundWhileTheBodyIsHeld, 'Dropping the chunks unwinds the handler while the body is still held.');
        $this->assertFalse($answered);
    }

    private function lockedBuilder(FiberSchedulerInterface $scheduler): Builder
    {
        $directory = sys_get_temp_dir().'/mcp-event-loop-'.bin2hex(random_bytes(4));

        return $this->builder($scheduler)->setSessionLock(new FileSessionLock($directory));
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, string>
     */
    private function handshake(Server $server, array $capabilities = []): array
    {
        $version = ProtocolVersion::V2025_11_25->value;
        $response = $this->post($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $version,
            'capabilities' => (object) $capabilities,
            'clientInfo' => ['name' => 'event-loop-client', 'version' => '1.0.0'],
        ]], []);
        $headers = ['Mcp-Session-Id' => $response->getHeaderLine('Mcp-Session-Id'), 'MCP-Protocol-Version' => $version];
        $this->post($server, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function handshakeCall(Server $server, array $headers, string $tool, int $id): ResponseInterface
    {
        return $this->post($server, ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => new \stdClass()]], $headers);
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function post(Server $server, array $message, array $headers): ResponseInterface
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream(json_encode($message, \JSON_THROW_ON_ERROR)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
    }

    private function builder(FiberSchedulerInterface $scheduler, bool $sessions = true): Builder
    {
        $builder = Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setFiberScheduler($scheduler);

        return $sessions ? $builder->setSession(new InMemorySessionStore()) : $builder;
    }

    /**
     * @param array<string, object> $capabilities
     * @param array<string, mixed>  $retry
     */
    private function modernCall(Server $server, string $tool, array $capabilities = [], array $retry = []): ResponseInterface
    {
        $version = ProtocolVersion::V2026_07_28->value;
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => new \stdClass(),
            '_meta' => [
                RequestMeta::PROTOCOL_VERSION => $version,
                RequestMeta::CLIENT_CAPABILITIES => (object) $capabilities,
            ],
            ...$retry,
        ]], \JSON_THROW_ON_ERROR);

        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('MCP-Protocol-Version', $version)
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', $tool)
            ->withBody($this->factory->createStream($body));

        return $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    private function text(ResponseInterface $response): mixed
    {
        return $this->decode($response)['result']['content'][0]['text'] ?? null;
    }
}
