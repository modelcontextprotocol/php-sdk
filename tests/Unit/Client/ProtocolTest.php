<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client\Protocol;
use Mcp\Schema\JsonRpc\Error;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ProtocolTest extends TestCase
{
    public function testNullIdErrorResponseIsLoggedAndDropped(): void
    {
        $logger = $this->createSpyLogger();
        $protocol = new Protocol(logger: $logger);

        $protocol->processMessage('{"jsonrpc": "2.0", "id": null, "error": {"code": -32700, "message": "Parse error"}}');

        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'warning' === $record['level']));

        $this->assertCount(1, $warnings);
        $this->assertNull($warnings[0]['context']['response']['id']);
        $this->assertSame(Error::PARSE_ERROR, $warnings[0]['context']['response']['error']['code']);
    }

    public function testErrorResponseWithIdIsStoredForItsPendingRequest(): void
    {
        $protocol = new Protocol();

        $protocol->processMessage('{"jsonrpc": "2.0", "id": 7, "error": {"code": -32601, "message": "Method not found"}}');

        $response = $protocol->getState()->consumeResponse(7);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(7, $response->getId());
        $this->assertSame(Error::METHOD_NOT_FOUND, $response->code);
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function createSpyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
