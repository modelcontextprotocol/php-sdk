<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Wire;

use Mcp\Schema\Wire\McpHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The encoding rules both sides of the wire depend on (SEP-2243).
 *
 * The round-trip property is what actually matters: whatever the client wraps,
 * the server's reader must recover unchanged. A rule that only one side gets
 * right produces a header mismatch the caller cannot explain.
 */
final class McpHeaderTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideValues(): iterable
    {
        yield 'plain ascii' => ['us-west1', 'us-west1'];
        yield 'empty string' => ['', ''];
        yield 'interior spaces stay plain' => ['us west 1', 'us west 1'];
        yield 'integer' => [42, '42'];
        yield 'boolean true' => [true, 'true'];
        yield 'boolean false' => [false, 'false'];
        yield 'non-ascii is wrapped' => ['Hello, 世界', '=?base64?SGVsbG8sIOS4lueVjA==?='];
        yield 'leading space is wrapped' => [' us-west1', '=?base64?IHVzLXdlc3Qx?='];
        yield 'trailing space is wrapped' => ['us-west1 ', '=?base64?dXMtd2VzdDEg?='];
        yield 'newline is wrapped' => ["line1\nline2", '=?base64?bGluZTEKbGluZTI=?='];
        yield 'tab is wrapped' => ["\tindented", '=?base64?CWluZGVudGVk?='];
        yield 'a literal already shaped like the wrapper is wrapped again' => ['=?base64?SGVsbG8=?=', '=?base64?PT9iYXNlNjQ/U0dWc2JHOD0/PQ==?='];
    }

    #[DataProvider('provideValues')]
    #[TestDox('renders a mirrored argument as a header value')]
    public function testEncode(mixed $value, string $expected): void
    {
        $this->assertSame($expected, McpHeader::encode($value));
    }

    #[DataProvider('provideValues')]
    #[TestDox('what the client wraps, the server recovers unchanged')]
    public function testRoundTrip(mixed $value, string $encoded): void
    {
        $expected = match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };

        $this->assertSame($expected, McpHeader::decode($encoded));
    }

    #[TestDox('a value that cannot be mirrored gets no header at all')]
    public function testUnmirrorableValue(): void
    {
        // A float has no single decimal spelling for a receiver to compare
        // against, which is why SEP-2243 forbids the annotation on `number`.
        $this->assertNull(McpHeader::encode(3.14159));
        $this->assertNull(McpHeader::encode(['a']));
        $this->assertNull(McpHeader::encode(null));
    }

    #[TestDox('a corrupted wrapper is refused rather than silently decoded')]
    public function testCorruptWrapperIsRefused(): void
    {
        $this->assertNull(McpHeader::decode('=?base64?not valid base64!?='));
    }

    #[TestDox('names the subject of the methods that address one, and nothing else')]
    public function testNameFor(): void
    {
        $this->assertSame('my-tool', McpHeader::nameFor('tools/call', ['name' => 'my-tool']));
        $this->assertSame('my-prompt', McpHeader::nameFor('prompts/get', ['name' => 'my-prompt']));
        $this->assertSame('file:///x', McpHeader::nameFor('resources/read', ['uri' => 'file:///x']));
        $this->assertSame('t-1', McpHeader::nameFor('tasks/get', ['taskId' => 't-1']));

        $this->assertNull(McpHeader::nameFor('tools/list', []));
        $this->assertNull(McpHeader::nameFor('tools/call', null));
    }
}
