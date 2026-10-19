<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Fixtures;

use Mcp\Server\Transport\InMemoryTransport;

/**
 * Records what the protocol sends, in the shape of the session's outgoing queue.
 */
final class RecordingTransport extends InMemoryTransport
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $sent = [];

    public function send(string $data, array $context): void
    {
        $this->sent[] = ['message' => $data, 'context' => $context];
    }
}
