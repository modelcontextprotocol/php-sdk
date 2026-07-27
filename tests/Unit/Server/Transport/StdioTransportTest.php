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

        self::assertSame([], $messages, 'the over-length line must never be dispatched');
    }

    #[TestDox('processing resumes with the next line after an over-length line is discarded')]
    public function testRecoversAfterOverlongLine(): void
    {
        $messages = [];
        $transport = $this->createTransport(str_repeat('a', 100)."\n".'{"valid":1}'."\n", $messages, maxLineBytes: 16);

        $this->pumpToEof($transport);

        self::assertSame(['{"valid":1}'], $messages);
    }

    #[TestDox('a normal line within the cap is dispatched')]
    public function testNormalLineIsDispatched(): void
    {
        $messages = [];
        $transport = $this->createTransport('{"jsonrpc":"2.0","id":1}'."\n", $messages);

        $this->pumpToEof($transport);

        self::assertSame(['{"jsonrpc":"2.0","id":1}'], $messages);
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
