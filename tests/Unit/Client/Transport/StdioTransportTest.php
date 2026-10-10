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
        $this->assertStringContainsString('no longer running', $response->message);
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
            $this->assertStringContainsString('no longer running', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $started);
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
