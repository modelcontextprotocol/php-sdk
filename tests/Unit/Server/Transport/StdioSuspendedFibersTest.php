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

use Mcp\Event\ServerRequestEvent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Several handshake-era tool calls in flight on one stdio connection, each
 * suspending its fiber.
 */
final class StdioSuspendedFibersTest extends TestCase
{
    #[TestDox('concurrent calls reporting progress all report and finish')]
    public function testConcurrentProgressCallsAllFinish(): void
    {
        $lines = $this->exchange([
            self::call(2, 'count', 'a'),
            self::call(3, 'count', 'b'),
            self::call(4, 'count', 'c'),
        ]);

        foreach ([2, 3, 4] as $id) {
            $this->assertSame('counted', $this->answer($lines, $id)['result']['content'][0]['text'] ?? null, \sprintf('call %d did not finish', $id));
        }

        $progress = array_filter($lines, static fn (array $line): bool => 'notifications/progress' === ($line['method'] ?? null));
        $this->assertCount(6, $progress);
    }

    #[TestDox('concurrent elicitations each resume with their own answer')]
    public function testConcurrentElicitationsResumeWithTheirOwnAnswer(): void
    {
        // Request ids are random, so the client answers once both elicitations went out, the second one first.
        $lines = $this->exchange([
            self::call(2, 'ask'),
            self::call(3, 'ask'),
        ], static fn (int|string $first, int|string $second): array => [
            ['jsonrpc' => '2.0', 'id' => $second, 'result' => ['action' => 'accept', 'content' => ['name' => 'second']]],
            ['jsonrpc' => '2.0', 'id' => $first, 'result' => ['action' => 'accept', 'content' => ['name' => 'first']]],
        ]);

        $elicitations = array_values(array_filter($lines, static fn (array $line): bool => 'elicitation/create' === ($line['method'] ?? null)));
        $this->assertCount(2, $elicitations);

        $this->assertSame('first', $this->answer($lines, 2)['result']['content'][0]['text'] ?? null);
        $this->assertSame('second', $this->answer($lines, 3)['result']['content'][0]['text'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function answer(array $lines, int $id): array
    {
        foreach ($lines as $line) {
            if ($id === ($line['id'] ?? null) && !isset($line['method'])) {
                return $line;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(int $id, string $tool, ?string $progressToken = null): array
    {
        $params = ['name' => $tool, 'arguments' => new \stdClass()];
        if (null !== $progressToken) {
            $params['_meta'] = ['progressToken' => $progressToken];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params];
    }

    /**
     * Runs a handshake-era session with the given messages after the handshake.
     *
     * @param list<array<string, mixed>>                                          $messages
     * @param (\Closure(int|string, int|string): list<array<string, mixed>>)|null $answer   the client's messages once the server sent two requests, given their ids
     *
     * @return list<array<string, mixed>>
     */
    private function exchange(array $messages, ?\Closure $answer = null): array
    {
        $input = fopen('php://temp', 'r+');
        $this->assertNotFalse($input);

        $requestIds = [];
        $dispatcher = new class(static function (ServerRequestEvent $event) use ($input, $answer, &$requestIds): void {
            $requestIds[] = $event->getRequest()->getId();
            if (null === $answer || 2 !== \count($requestIds)) {
                return;
            }

            // Appended behind what the server has yet to read.
            $position = ftell($input);
            fseek($input, 0, \SEEK_END);
            foreach ($answer(...$requestIds) as $message) {
                fwrite($input, json_encode($message, \JSON_THROW_ON_ERROR)."\n");
            }
            fwrite($input, str_repeat("\n", 5));
            fseek($input, (int) $position);
        }) implements EventDispatcherInterface {
            public function __construct(private readonly \Closure $onServerRequest)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof ServerRequestEvent) {
                    ($this->onServerRequest)($event);
                }

                return $event;
            }
        };

        $server = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->progress(1, 2);
                $context->getClientGateway()->progress(2, 2);

                return 'counted';
            }, name: 'count', description: 'Reports progress')
            ->addTool(static function (RequestContext $context): string {
                $result = $context->getClientGateway()->elicit('Name?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']));

                return $result->content['name'] ?? 'none';
            }, name: 'ask', description: 'Asks for a name')
            ->setEventDispatcher($dispatcher)
            ->build();

        // A file rather than memory: running the server closes its streams.
        $outputFile = tempnam(sys_get_temp_dir(), 'mcp-stdio');
        $output = fopen($outputFile, 'w');
        $this->assertNotFalse($output);

        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => ['elicitation' => new \stdClass()],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$messages,
        ];

        foreach ($messages as $message) {
            fwrite($input, json_encode($message, \JSON_THROW_ON_ERROR)."\n");
        }

        // Blank lines give the run loop a few more turns before the input ends.
        fwrite($input, str_repeat("\n", 5));
        rewind($input);

        $server->run(new StdioTransport($input, $output));

        $written = (string) file_get_contents($outputFile);
        unlink($outputFile);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $written))),
        );
    }
}
