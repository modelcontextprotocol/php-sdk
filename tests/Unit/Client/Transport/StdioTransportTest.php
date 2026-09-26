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

use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
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

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StdioTransport(command: 'true', maxBufferSize: 0);
    }

    #[TestDox('a child that exits before responding fails the request fast with its exit code and stderr')]
    public function testProcessExitBeforeResponseFailsFast(): void
    {
        $transport = new StdioTransport(command: \PHP_BINARY, args: ['-r', 'fwrite(\STDERR, "boot failure detail"); exit(7);']);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        $fiber = $this->suspendedFiber();
        $this->setPrivate($transport, 'activeFiber', $fiber);

        $this->invokeSpawn($transport);
        // Pumps processFiber() alone; it captures the final stderr itself, so
        // the assertions do not depend on a prior processStderr() tick.
        $this->pumpUntilResolved($transport, $fiber);

        $this->assertTrue($fiber->isTerminated(), 'the waiting fiber must be resolved once the process is gone');
        $error = $fiber->getReturn();
        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame(Error::INTERNAL_ERROR, $error->code);
        $this->assertSame(1, $error->id);
        $this->assertStringContainsString('code 7', $error->message, 'the exit code belongs in the message');
        $this->assertStringContainsString('boot failure detail', $error->message, 'captured stderr belongs in the message');

        $transport->close();
    }

    #[TestDox('a response that arrived before the process exited still wins over the exit error')]
    public function testResponseBeforeExitIsNotOverwritten(): void
    {
        $transport = new StdioTransport(command: \PHP_BINARY, args: ['-r', 'exit(0);']);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $state->storeResponse(1, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]);
        $transport->setState($state);

        $fiber = $this->suspendedFiber();
        $this->setPrivate($transport, 'activeFiber', $fiber);

        $this->invokeSpawn($transport);
        $this->pumpUntilResolved($transport, $fiber);

        $this->assertTrue($fiber->isTerminated());
        $this->assertInstanceOf(Response::class, $fiber->getReturn(), 'the buffered response must not be replaced by the exit error');

        $transport->close();
    }

    #[TestDox('a response larger than one read that arrives just before the process exits is not clobbered')]
    public function testLargeResponseArrivingBeforeExitIsNotClobbered(): void
    {
        // 9000 zero bytes on one line: larger than the 8 KiB read size, so the
        // frame only completes if stdout is fully drained within the tick.
        $transport = new StdioTransport(command: \PHP_BINARY, args: ['-r', 'echo str_repeat("0", 9000), "\n"; exit(0);']);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        // Stand in for the session: a delivered frame becomes the stored response.
        $transport->onMessage(static function (string $line) use ($state): void {
            if (\strlen($line) > 8192) {
                $state->storeResponse(1, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]);
            }
        });

        $fiber = $this->suspendedFiber();
        $this->setPrivate($transport, 'activeFiber', $fiber);

        $this->invokeSpawn($transport);
        $this->pumpTicksUntilResolved($transport, $fiber);

        $this->assertTrue($fiber->isTerminated());
        $this->assertInstanceOf(Response::class, $fiber->getReturn(), 'a fully-arrived response must win over the exit error');

        $transport->close();
    }

    /**
     * A fiber that suspends once and returns whatever value resumes it — a
     * stand-in for a request fiber awaiting its response.
     *
     * @return \Fiber<null, mixed, mixed, null>
     */
    private function suspendedFiber(): \Fiber
    {
        $fiber = new \Fiber(static fn () => \Fiber::suspend());
        $fiber->start();

        return $fiber;
    }

    private function invokeSpawn(StdioTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'spawnProcess'))->invoke($transport);
    }

    private function invokeProcessFiber(StdioTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'processFiber'))->invoke($transport);
    }

    private function invokeTick(StdioTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'tick'))->invoke($transport);
    }

    private function setPrivate(StdioTransport $transport, string $property, mixed $value): void
    {
        (new \ReflectionProperty($transport, $property))->setValue($transport, $value);
    }

    /**
     * Drive processFiber() until it resolves the waiting fiber or a deadline
     * elapses. The transport itself makes the first proc_get_status() call
     * after the child exits, so the exit code stays readable for the code
     * under test.
     *
     * @param \Fiber<null, mixed, mixed, null> $fiber
     */
    private function pumpUntilResolved(StdioTransport $transport, \Fiber $fiber): void
    {
        $deadline = microtime(true) + 2.0;
        while (!$fiber->isTerminated() && microtime(true) < $deadline) {
            $this->invokeProcessFiber($transport);
            usleep(2000);
        }
    }

    /**
     * Like {@see pumpUntilResolved()} but drives the full tick(), so stdout is
     * read and parsed before the exit check runs.
     *
     * @param \Fiber<null, mixed, mixed, null> $fiber
     */
    private function pumpTicksUntilResolved(StdioTransport $transport, \Fiber $fiber): void
    {
        $deadline = microtime(true) + 2.0;
        while (!$fiber->isTerminated() && microtime(true) < $deadline) {
            $this->invokeTick($transport);
            usleep(2000);
        }
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
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
