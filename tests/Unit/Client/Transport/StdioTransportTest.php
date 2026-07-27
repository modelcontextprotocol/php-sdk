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
