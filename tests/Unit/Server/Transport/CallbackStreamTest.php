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

use Mcp\Exception\LogicException;
use Mcp\Server\Transport\CallbackStream;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CallbackStreamTest extends TestCase
{
    #[TestDox('a stream built from a callback alone has no chunks to hand out')]
    public function testAStreamWithoutChunksHasNone(): void
    {
        $stream = new CallbackStream(static function (): void {});

        $this->assertNull($stream->chunks());
        $this->assertFalse($stream->eof());
    }

    #[TestDox('the chunks are the body: once taken, reading the stream writes nothing')]
    public function testTakingTheChunksProducesTheBody(): void
    {
        $written = false;
        $stream = new CallbackStream(static function () use (&$written): void {
            $written = true;
        }, new NullLogger(), static function (): \Generator {
            yield 'data: {}'."\n\n";
        });

        $chunks = $stream->chunks();

        $this->assertNotNull($chunks);
        $this->assertSame(['data: {}'."\n\n"], iterator_to_array($chunks, false));
        $this->assertSame('', (string) $stream);
        $this->assertFalse($written);
        $this->assertTrue($stream->eof());
    }

    #[TestDox('the chunks of a body already produced are refused')]
    public function testTheChunksOfAProducedBodyAreRefused(): void
    {
        $stream = new CallbackStream(static function (): void {}, new NullLogger(), static function (): \Generator {
            yield '';
        });
        (string) $stream;

        $this->expectException(LogicException::class);

        $stream->chunks();
    }

    #[TestDox('the chunks are handed out once')]
    public function testTheChunksAreHandedOutOnce(): void
    {
        $stream = new CallbackStream(static function (): void {}, new NullLogger(), static function (): \Generator {
            yield '';
        });
        $stream->chunks();

        $this->expectException(LogicException::class);

        $stream->chunks();
    }
}
