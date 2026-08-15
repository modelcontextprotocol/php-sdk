<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    public function testProtocolVersionComesFromTheSessionInTheHandshakeEra(): void
    {
        $context = new RequestContext(
            $this->createSession('2025-06-18'),
            $this->createRequest(),
        );

        $this->assertSame(ProtocolVersion::V2025_06_18, $context->getProtocolVersion());
    }

    public function testPerRequestMetaTakesPrecedenceOverTheSession(): void
    {
        // Modern revisions have no `initialize`, so the revision travels with every
        // single request instead of being negotiated once.
        $context = new RequestContext(
            $this->createSession('2025-11-25'),
            $this->createRequest(['io.modelcontextprotocol/protocolVersion' => '2026-07-28']),
        );

        $this->assertSame(ProtocolVersion::V2026_07_28, $context->getProtocolVersion());
    }

    /**
     * @dataProvider provideUnusableVersions
     */
    public function testUnusableVersionFallsBackToTheNewestHandshakeRevision(mixed $stored): void
    {
        $context = new RequestContext(
            $this->createSession($stored),
            $this->createRequest(),
        );

        $this->assertSame(ProtocolVersion::latestHandshake(), $context->getProtocolVersion());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideUnusableVersions(): iterable
    {
        yield 'never negotiated' => [null];
        yield 'unknown revision' => ['1999-01-01'];
        yield 'not a string' => [20260728];
    }

    private function createSession(mixed $protocolVersion): SessionInterface
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('protocol_version')->willReturn($protocolVersion);

        return $session;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function createRequest(?array $meta = null): CallToolRequest
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => CallToolRequest::getMethod(),
            'id' => 'test-request',
            'params' => ['name' => 'test_tool', 'arguments' => []],
        ]);

        return null === $meta ? $request : $request->withMeta($meta);
    }
}
