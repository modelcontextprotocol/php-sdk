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
use Mcp\Client\Configuration;
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\LoggingMessageNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class StdioTransportTest extends TestCase
{
    #[TestDox('input buffer is bounded: an over-length frame without a newline is aborted')]
    public function testInputBufferIsBounded(): void
    {
        $transport = new StdioTransport(command: 'true', maxBufferSize: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        // A server that floods stdout without ever emitting a newline.
        $this->setStdout($transport, $this->stream(str_repeat('a', 8192)));

        $this->invokeProcessInput($transport);

        $this->assertSame('', $this->readPrivate($transport, 'inputBuffer'), 'buffer must be cleared on abort');
    }

    #[TestDox('aborting the input fails the in-flight request immediately')]
    public function testAbortFailsPendingRequestFast(): void
    {
        $transport = new StdioTransport(command: 'true', maxBufferSize: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        $this->setStdout($transport, $this->stream(str_repeat('a', 8192)));

        $this->invokeProcessInput($transport);

        $response = $state->consumeResponse(1);
        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::INTERNAL_ERROR, $response->code);
        $this->assertSame(1, $response->id);
    }

    #[TestDox('newline-delimited frames within the cap are parsed and dispatched')]
    public function testWellFormedFramesStillParse(): void
    {
        $transport = new StdioTransport(command: 'true');
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->setStdout($transport, $this->stream('{"a":1}'."\n".'{"b":2}'."\n"));

        $this->invokeProcessInput($transport);

        $this->assertSame(['{"a":1}', '{"b":2}'], $messages);
    }

    #[TestDox('a server closing its output fails what is pending as answers, so nothing is left to time out later')]
    public function testClosedOutputFailsPendingRequests(): void
    {
        $transport = new StdioTransport(command: 'true');
        $state = new ClientState();
        $state->setInitialized(true);
        $transport->setState($state);
        $state->addPendingRequest(1, 120);

        $this->setStdout($transport, $this->stream(''));
        $this->invokeProcessInput($transport);

        $response = $state->consumeResponse(1);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame('The server process closed its output.', $response->message);
        $this->assertSame([TransportInterface::CONNECTION_LOST => true], $response->data);
        $this->assertFalse($state->isInitialized());
    }

    #[TestDox('a server that can no longer be written to leaves the client uninitialized')]
    public function testFailedWriteUninitializesTheClient(): void
    {
        $transport = new StdioTransport(command: 'true');
        $state = new ClientState();
        $state->setInitialized(true);
        $transport->setState($state);

        $stdin = fopen('php://temp', 'r');
        $this->assertNotFalse($stdin);
        (new \ReflectionProperty($transport, 'stdin'))->setValue($transport, $stdin);

        try {
            $transport->send('{"jsonrpc":"2.0","id":1,"method":"ping"}');
            $this->fail('Writing to a server that is gone must fail.');
        } catch (ConnectionException) {
        }

        $this->assertFalse($state->isInitialized());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function progressAmongNotificationsProvider(): iterable
    {
        $progress = '{"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"t","progress":1}}';
        $log = '{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"done"}}';

        yield 'one per line' => [$progress."\n".$log."\n"];
        yield 'in one batch' => ['['.$progress.','.$log.']'."\n"];
    }

    #[DataProvider('progressAmongNotificationsProvider')]
    #[TestDox('progress and other notifications read in one go reach the caller in the order they were sent: $_dataName')]
    public function testProgressKeepsItsPlaceAmongNotifications(string $lines): void
    {
        $order = [];
        $protocol = new Protocol(notificationHandlers: [new LoggingNotificationHandler(static function (LoggingMessageNotification $n) use (&$order): void {
            $order[] = 'log '.$n->data;
        })]);
        $transport = new StdioTransport(command: 'true');
        $protocol->connect($transport, new Configuration(new Implementation('test', '1.0.0'), new ClientCapabilities()));
        $protocol->setProgressCallback(static function (float $progress) use (&$order): void {
            $order[] = 'progress '.$progress;
        });
        // As if the request that carried the token were in flight.
        (new \ReflectionProperty($protocol, 'progressToken'))->setValue($protocol, 't');

        // The partial line keeps the stream open, so the read is about ordering and not the server leaving.
        $this->setStdout($transport, $this->stream($lines.'{"partial":'));
        $this->invokeProcessInput($transport);

        $this->assertSame(['progress 1', 'log done'], $order);
    }

    #[TestDox('a final answer read together with the end of the output is kept, not overwritten by the failure')]
    public function testFinalAnswerBeforeClosedOutputIsKept(): void
    {
        $transport = new StdioTransport(command: 'true');
        $state = new ClientState();
        $transport->setState($state);
        $transport->onMessage(static function (string $message) use ($state): void {
            $state->storeResponse(1, json_decode($message, true, flags: \JSON_THROW_ON_ERROR));
        });
        $state->addPendingRequest(1, 120);

        $this->setStdout($transport, $this->stream('{"jsonrpc":"2.0","id":1,"result":{}}'."\n"));
        $this->invokeProcessInput($transport);

        $this->assertInstanceOf(Response::class, $state->consumeResponse(1));
    }

    #[TestDox('a server that exits fails the connection at once instead of timing out')]
    public function testExitedServerFailsTheConnection(): void
    {
        $client = Client::builder()->setInitTimeout(10)->setMaxRetries(0)->build();
        $started = microtime(true);

        try {
            $client->connect(new StdioTransport(command: \PHP_BINARY, args: ['-r', 'exit(1);']));
            $this->fail('Connecting to a server that exits must fail.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('exited with code 1', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $started);
    }

    #[TestDox('a server that dies tells why: its exit code and the end of its stderr')]
    public function testExitedServerReportsExitCodeAndStderr(): void
    {
        $client = Client::builder()->setInitTimeout(10)->setMaxRetries(0)->build();

        try {
            $client->connect(new StdioTransport(command: \PHP_BINARY, args: ['-r', 'fwrite(STDERR, str_repeat("x", 9000)."Fatal: missing extension\n"); exit(7);']));
            $this->fail('Connecting to a server that exits must fail.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('exited with code 7: ', $e->getMessage());
            $this->assertStringEndsWith('Fatal: missing extension', $e->getMessage());
            $this->assertLessThan(StdioTransport::MAX_STDERR_BUFFER_SIZE + 100, \strlen($e->getMessage()), 'only the tail of stderr is kept');
        }
    }

    #[TestDox('a server killed by a signal says so, instead of an exit code it never had')]
    public function testKilledServerReportsTheSignal(): void
    {
        // Where /bin/sh forks the server instead of becoming it, the signal hits
        // the shell, so the server lets go of the output itself.
        $transport = new StdioTransport(command: \PHP_BINARY, args: ['-r', 'fclose(STDOUT); usleep(500000);']);
        $state = new ClientState();
        $transport->setState($state);
        $state->addPendingRequest(1, 120);

        (new \ReflectionMethod($transport, 'spawnProcess'))->invoke($transport);

        try {
            proc_terminate($this->readPrivate($transport, 'process'), 9);

            $deadline = microtime(true) + 5;
            while (null === ($response = $state->consumeResponse(1)) && microtime(true) < $deadline) {
                $this->invokeProcessInput($transport);
                usleep(1000);
            }
        } finally {
            $transport->close();
        }

        $this->assertInstanceOf(Error::class, $response);
        $this->assertStringStartsWith('The server process was killed by signal 9', $response->message);
    }

    #[TestDox('a retried connection reports how its own process ended, not the previous one')]
    public function testRetryReportsItsOwnProcess(): void
    {
        $counter = tempnam(sys_get_temp_dir(), 'mcp-stdio-');
        $client = Client::builder()->setInitTimeout(10)->setMaxRetries(1)->build();

        try {
            $client->connect(new StdioTransport(command: \PHP_BINARY, args: ['-r', '$n = (int) file_get_contents($argv[1]) + 1; file_put_contents($argv[1], $n); fwrite(STDERR, "run $n"); exit(10 + $n);', $counter]));
            $this->fail('Connecting to a server that exits must fail.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('exited with code 12: run 2', $e->getMessage());
            $this->assertStringNotContainsString('run 1', $e->getMessage());
        } finally {
            unlink($counter);
        }
    }

    #[TestDox('a server that never stops writing to stderr does not hold up the transport')]
    public function testEndlessStderrDoesNotBlock(): void
    {
        $endless = new class {
            /** @var resource|null */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return true;
            }

            public function stream_read(int $count): string
            {
                return str_repeat('x', $count);
            }

            public function stream_eof(): bool
            {
                return false;
            }
        };

        stream_wrapper_register('mcp-endless', $endless::class);

        try {
            $transport = new StdioTransport(command: 'true');
            $stream = fopen('mcp-endless://', 'r');
            $this->assertNotFalse($stream);
            (new \ReflectionProperty($transport, 'stderr'))->setValue($transport, $stream);

            (new \ReflectionMethod($transport, 'processStderr'))->invoke($transport);

            $this->assertSame(StdioTransport::MAX_STDERR_BUFFER_SIZE, \strlen($this->readPrivate($transport, 'stderrBuffer')));
        } finally {
            stream_wrapper_unregister('mcp-endless');
        }
    }

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StdioTransport(command: 'true', maxBufferSize: 0);
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function setStdout(StdioTransport $transport, mixed $stream): void
    {
        (new \ReflectionProperty($transport, 'stdout'))->setValue($transport, $stream);
    }

    private function invokeProcessInput(StdioTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'processInput'))->invoke($transport);
    }

    private function readPrivate(StdioTransport $transport, string $property): mixed
    {
        return (new \ReflectionProperty($transport, $property))->getValue($transport);
    }
}
