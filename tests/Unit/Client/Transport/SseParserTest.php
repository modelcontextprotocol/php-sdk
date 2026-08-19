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

use Mcp\Client\Transport\SseParser;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function delimiterProvider(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[DataProvider('delimiterProvider')]
    #[TestDox('events are split on $_dataName line endings')]
    public function testSplitsEvents(string $eol): void
    {
        $parser = new SseParser(1024);
        $parser->append('data: first'.$eol.$eol.'data: second'.$eol.$eol.'data: thi');

        $this->assertSame(['first', 'second'], self::drain($parser));

        $parser->append('rd'.$eol.$eol);

        $this->assertSame(['third'], self::drain($parser));
    }

    #[TestDox('fields follow the SSE specification')]
    public function testParsesFields(): void
    {
        $parser = new SseParser(1024);
        $parser->append(": comment\nid: 42\nretry: 500\nevent: message\ndata: one\ndata:  two\ndata\n\n");

        $this->assertSame(['data' => "one\n two\n", 'id' => '42', 'retry' => 500], $parser->next());
    }

    #[TestDox('an ID containing NUL and a retry value that is not an integer are ignored')]
    public function testIgnoresInvalidIdAndRetry(): void
    {
        $parser = new SseParser(1024);
        $parser->append("id: a\0b\nretry: 1.5\n\nretry: 99999999999999999999\ndata: x\n\n");

        $this->assertSame(['data' => '', 'id' => null, 'retry' => null], $parser->next());
        $this->assertSame(['data' => 'x', 'id' => null, 'retry' => null], $parser->next());
    }

    #[TestDox('blank events are skipped')]
    public function testSkipsBlankEvents(): void
    {
        $parser = new SseParser(1024);
        $parser->append("\n\n \n\ndata: x\n\n");

        $this->assertSame(['x'], self::drain($parser));
    }

    #[TestDox('the rest of an ended stream is returned once, even when truncated')]
    public function testRest(): void
    {
        $parser = new SseParser(1024);
        $parser->append("id: 1\ndata: {\"trunc");

        $this->assertNull($parser->next());
        $this->assertSame(['data' => '{"trunc', 'id' => '1', 'retry' => null], $parser->rest());
        $this->assertNull($parser->rest());
    }

    #[TestDox('a chunk that would exceed the buffer cap is refused')]
    public function testRefusesChunkOverCap(): void
    {
        $parser = new SseParser(8);

        $this->assertTrue($parser->append('data: x'));
        $this->assertFalse($parser->append('yz'));
        $this->assertSame(7, $parser->bufferedBytes());
    }

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SseParser(0);
    }

    /**
     * @return list<string>
     */
    private static function drain(SseParser $parser): array
    {
        $data = [];
        while (null !== ($event = $parser->next())) {
            $data[] = $event['data'];
        }

        return $data;
    }
}
