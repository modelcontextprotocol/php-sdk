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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class StdioTransportTest extends TestCase
{
    #[TestDox('a line exceeding the byte cap is discarded instead of buffered')]
    public function testOverlongLineIsDiscarded(): void
    {
        $messages = [];
        $transport = $this->createTransport(str_repeat('a', 100)."\n", $messages, maxLineBytes: 16);

        $this->pumpToEof($transport);

        $this->assertSame([], $messages, 'the over-length line must never be dispatched');
    }

    #[TestDox('processing resumes with the next line after an over-length line is discarded')]
    public function testRecoversAfterOverlongLine(): void
    {
        $messages = [];
        $transport = $this->createTransport(str_repeat('a', 100)."\n".'{"valid":1}'."\n", $messages, maxLineBytes: 16);

        $this->pumpToEof($transport);

        $this->assertSame(['{"valid":1}'], $messages);
    }

    #[TestDox('the byte cap counts the newline')]
    public function testCapCountsTheNewline(): void
    {
        $messages = [];
        $transport = $this->createTransport(str_repeat('a', 9)."\n".str_repeat('b', 10)."\n", $messages, maxLineBytes: 10);

        $this->pumpToEof($transport);

        $this->assertSame([str_repeat('a', 9)], $messages);
    }

    #[TestDox('a normal line within the cap is dispatched')]
    public function testNormalLineIsDispatched(): void
    {
        $messages = [];
        $transport = $this->createTransport('{"jsonrpc":"2.0","id":1}'."\n", $messages);

        $this->pumpToEof($transport);

        $this->assertSame(['{"jsonrpc":"2.0","id":1}'], $messages);
    }

    #[TestDox('a line arriving on an idle input is read as soon as it arrives')]
    public function testIdleInputWakesOnArrival(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is needed to feed the transport from a real pipe.');
        }

        // A slow host only adds delay, while a wait that misses the input never gets below the limit.
        $fastest = \INF;
        for ($attempt = 0; $attempt < 3 && $fastest >= 45; ++$attempt) {
            $fastest = min($fastest, $this->measureIdleWake());
        }

        $this->assertLessThan(45, $fastest, 'the idle wait must end when input arrives');
    }

    private function measureIdleWake(): float
    {
        // The child sends a request, waits for the answer, idles for 10ms, then sends another and exits.
        $process = proc_open(
            [\PHP_BINARY, '-r', 'echo "{\"first\":1}\n"; fgets(STDIN); usleep(10000); echo "{\"second\":1}\n";'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $answeredAt = null;
        $arrivedAt = null;
        $transport = new StdioTransport(input: $pipes[1], output: $pipes[0]);
        $transport->onMessage(static function (TransportInterface $transport) use (&$answeredAt, &$arrivedAt): void {
            if (null === $answeredAt) {
                $transport->send('{"answer":1}', []);
                $answeredAt = hrtime(true);

                return;
            }

            $arrivedAt = hrtime(true);
        });

        $transport->listen();
        proc_close($process);

        $this->assertNotNull($answeredAt);
        $this->assertNotNull($arrivedAt);

        return ($arrivedAt - $answeredAt) / 1e6;
    }

    #[TestDox('an idle pipe does not hold up the run loop')]
    public function testIdlePipeDoesNotHoldUpTheRunLoop(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is needed to feed the transport from a real pipe.');
        }

        if ('Windows' === \PHP_OS_FAMILY && \PHP_VERSION_ID < 80500) {
            $this->markTestSkipped('Before PHP 8.5, stream_select() on Windows reports an idle pipe as readable.');
        }

        $process = proc_open([\PHP_BINARY, '-r', 'sleep(3);'], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);

        $transport = new StdioTransport(input: $pipes[1], output: $this->stream(''));
        $start = hrtime(true);
        (new \ReflectionMethod($transport, 'processInput'))->invoke($transport);
        $elapsed = (hrtime(true) - $start) / 1e6;

        proc_terminate($process);
        proc_close($process);

        $this->assertLessThan(1000, $elapsed, 'a tick must not wait for input longer than the idle wait');
    }

    #[TestDox('an input that cannot be selected is still read')]
    public function testUnselectableInputIsRead(): void
    {
        $input = fopen('php://memory', 'r+');
        $this->assertNotFalse($input);
        fwrite($input, '{"valid":1}'."\n");
        rewind($input);

        $messages = [];
        $transport = new StdioTransport(input: $input, output: $this->stream(''));
        $transport->onMessage(static function ($transport, string $payload) use (&$messages): void {
            $messages[] = $payload;
        });

        $this->pumpToEof($transport);

        $this->assertSame(['{"valid":1}'], $messages);
    }

    #[TestDox('an idle input that cannot be selected does not spin the run loop')]
    public function testIdleUnselectableInputDoesNotSpin(): void
    {
        $wrapper = new class {
            /** @var resource|null */
            public $context;

            public function stream_open(): bool
            {
                return true;
            }

            public function stream_read(): string
            {
                return '';
            }

            public function stream_eof(): bool
            {
                return false;
            }
        };

        stream_wrapper_register('mcp-idle', $wrapper::class);

        try {
            $input = fopen('mcp-idle://input', 'r');
            $this->assertNotFalse($input);

            $transport = new StdioTransport(input: $input, output: $this->stream(''));
            $start = hrtime(true);
            (new \ReflectionMethod($transport, 'processInput'))->invoke($transport);
            $elapsed = (hrtime(true) - $start) / 1e6;
        } finally {
            stream_wrapper_unregister('mcp-idle');
        }

        $this->assertGreaterThanOrEqual(45, $elapsed, 'a tick without input must still wait');
    }

    #[TestDox('the line byte cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StdioTransport(input: $this->stream(''), output: $this->stream(''), maxLineBytes: 0);
    }

    /**
     * @param list<string> $messages
     */
    private function createTransport(string $input, array &$messages, int $maxLineBytes = 4 * 1024 * 1024): StdioTransport
    {
        $transport = new StdioTransport(
            input: $this->stream($input),
            output: $this->stream(''),
            maxLineBytes: $maxLineBytes,
        );

        $transport->onMessage(static function ($transport, string $payload) use (&$messages): void {
            $messages[] = $payload;
        });

        return $transport;
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

    private function pumpToEof(StdioTransport $transport): void
    {
        $processInput = new \ReflectionMethod($transport, 'processInput');
        $input = (new \ReflectionProperty($transport, 'input'))->getValue($transport);

        for ($i = 0; $i < 1000 && !feof($input); ++$i) {
            $processInput->invoke($transport);
        }
    }
}
