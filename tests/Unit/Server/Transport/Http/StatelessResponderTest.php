<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http;

use Mcp\Server\Stateless\StatelessResult;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\Http\StatelessResponder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class StatelessResponderTest extends TestCase
{
    #[TestDox('a streamed answer read as a stream writes each frame, and a keep-alive on an idle turn')]
    public function testReadingTheStreamWritesTheFrames(): void
    {
        // The stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
        $output = '';
        $flushed = [];
        ob_start(static function (string $chunk) use (&$output, &$flushed): string {
            $output .= $chunk;
            if ('' !== $chunk) {
                $flushed[] = $chunk;
            }

            return '';
        });
        $start = microtime(true);
        try {
            (string) $this->stream()->getBody();
        } finally {
            ob_end_flush();
        }

        $this->assertSame('data: {"method":"first"}'."\n\n".': keep-alive'."\n\n".'data: {"id":1}'."\n\n", $output);
        $elapsed = microtime(true) - $start;

        $this->assertSame(['data: {"method":"first"}'."\n\n", ': keep-alive'."\n\n", 'data: {"id":1}'."\n\n"], $flushed, 'Each frame is flushed on its own.');
        // The turn sleeps 250 ms, which Windows can end slightly early.
        $this->assertGreaterThanOrEqual(0.2, $elapsed, 'The idle turn waits before the next poll.');
        $this->assertLessThan(1.0, $elapsed, 'The idle turn waits once.');
    }

    #[TestDox('a streamed answer without an idle turn is written without waiting')]
    public function testFramesAreWrittenWithoutWaiting(): void
    {
        $factory = new Psr17Factory();
        $response = (new StatelessResponder($factory, $factory))->respond(StatelessResult::stream(static function (): \Generator {
            yield ['method' => 'first'];
            yield ['method' => 'second'];
            yield ['id' => 1];
        }));

        ob_start(static fn (): string => '');
        $start = microtime(true);
        try {
            (string) $response->getBody();
        } finally {
            ob_end_flush();
        }

        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    #[TestDox('a runtime writing the body itself gets the same frames, and an empty chunk for an idle turn')]
    public function testTheChunksAreTheFrames(): void
    {
        $body = $this->stream()->getBody();
        $this->assertInstanceOf(CallbackStream::class, $body);

        $chunks = $body->chunks();

        $this->assertNotNull($chunks);
        $this->assertSame(['data: {"method":"first"}'."\n\n", '', 'data: {"id":1}'."\n\n"], iterator_to_array($chunks, false));
    }

    private function stream(): ResponseInterface
    {
        $factory = new Psr17Factory();

        return (new StatelessResponder($factory, $factory))->respond(StatelessResult::stream(static function (): \Generator {
            yield ['method' => 'first'];
            yield null;
            yield ['id' => 1];
        }));
    }
}
