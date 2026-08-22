<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Stateless;

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Stateless\StandardHeaderValidator;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * What the client puts on the wire, checked against the server that reads it.
 *
 * Every case asserts the emitted headers and then feeds them straight to
 * {@see StandardHeaderValidator}: agreeing with a hand-written expectation is
 * not the point, agreeing with the other side is.
 */
final class HeaderFactoryTest extends TestCase
{
    #[TestDox('declares the revision and the method on every request')]
    public function testStandardHeaders(): void
    {
        $headers = $this->headersFor(['method' => 'tools/list', 'params' => []]);

        $this->assertSame('2026-07-28', $headers['MCP-Protocol-Version']);
        $this->assertSame('tools/list', $headers['Mcp-Method']);
        $this->assertArrayNotHasKey('Mcp-Name', $headers);
    }

    #[TestDox('names the subject of a request that addresses one')]
    public function testNameHeader(): void
    {
        $payload = ['method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => []]];

        $this->assertSame('search', $this->headersFor($payload)['Mcp-Name']);
        $this->assertNull($this->validate($payload));
    }

    #[TestDox('wraps a subject that is not header-safe, and the server unwraps it')]
    public function testUnsafeNameRoundTrips(): void
    {
        $payload = ['method' => 'resources/read', 'params' => ['uri' => 'file:///café.txt']];

        $this->assertSame('=?base64?ZmlsZTovLy9jYWbDqS50eHQ=?=', $this->headersFor($payload)['Mcp-Name']);
        $this->assertNull($this->validate($payload));
    }

    #[TestDox('mirrors annotated tool arguments the server can verify')]
    public function testMirroredArguments(): void
    {
        $payload = [
            'method' => 'tools/call',
            'params' => [
                'name' => 'search',
                'arguments' => ['region' => ' padded ', 'priority' => 7],
            ],
        ];

        $headers = $this->headersFor($payload);

        $this->assertSame('=?base64?IHBhZGRlZCA=?=', $headers['Mcp-Param-Region']);
        $this->assertSame('7', $headers['Mcp-Param-Priority']);
        $this->assertNull($this->validate($payload));
    }

    #[TestDox('a response carries the revision but nothing to mirror')]
    public function testResponseCarriesOnlyTheVersion(): void
    {
        $headers = $this->headersFor(['id' => 1, 'result' => []]);

        $this->assertSame(['MCP-Protocol-Version' => '2026-07-28'], $headers);
    }

    #[TestDox('mirrors trace context an application put in `_meta` onto its native headers')]
    public function testTraceContextMirrorsOntoHeaders(): void
    {
        $payload = [
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
                    'tracestate' => 'acme=1',
                ],
            ],
        ];

        $headers = $this->headersFor($payload);

        $this->assertSame('00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01', $headers['traceparent']);
        $this->assertSame('acme=1', $headers['tracestate']);
    }

    #[TestDox('a response with no method still mirrors its trace context')]
    public function testTraceContextMirrorsOntoAResponse(): void
    {
        $payload = ['id' => 1, 'result' => [], 'params' => ['_meta' => ['traceparent' => 'tp-1']]];

        $this->assertSame(
            ['MCP-Protocol-Version' => '2026-07-28', 'traceparent' => 'tp-1'],
            $this->headersFor($payload),
        );
    }

    #[TestDox('an untraced request mirrors nothing')]
    public function testNoTraceContextMirrorsNothing(): void
    {
        $headers = $this->headersFor(['method' => 'tools/list', 'params' => []]);

        $this->assertArrayNotHasKey('traceparent', $headers);
        $this->assertArrayNotHasKey('tracestate', $headers);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function headersFor(array $payload): array
    {
        return (new HeaderFactory($this->catalog()))->forMessage($payload, ProtocolVersion::V2026_07_28);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string|null the server's reason to reject, or null when it agrees
     */
    private function validate(array $payload): ?string
    {
        return (new StandardHeaderValidator())->validate(
            $payload['method'],
            $payload['params'] ?? null,
            $this->headersFor($payload),
        );
    }

    private function catalog(): ToolCatalog
    {
        $catalog = new ToolCatalog();
        $catalog->record([[
            'name' => 'search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
                ],
            ],
        ]]);

        return $catalog;
    }
}
