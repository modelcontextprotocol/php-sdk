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
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\StreamableHttpTransport;
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

    private function builder(FiberSchedulerInterface $scheduler): Builder
    {
        return Server::builder()
            ->setServerInfo('event-loop-server', '1.0.0')
            ->setFiberScheduler($scheduler);
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
