<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Server\Transport\Sse;

use Generator;
use Mcp\Server\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StreamTransport implements TransportInterface
{
    public function __construct(
        private readonly string $messageEndpoint,
        private readonly StoreInterface $store,
        private readonly Uuid $id,
    ) {
    }

    public function initialize(): void
    {
        ignore_user_abort(true);
        $this->flushEvent('endpoint', $this->messageEndpoint);
    }

    public function isConnected(): bool
    {
        return 0 === connection_aborted();
    }

    public function receive(): Generator
    {
        yield $this->store->pop($this->id);
    }

    public function send(string $data): void
    {
        $this->flushEvent('message', $data);
    }

    public function close(): void
    {
        $this->store->remove($this->id);
    }

    private function flushEvent(string $event, string $data): void
    {
        echo \sprintf('event: %s', $event).\PHP_EOL;
        echo \sprintf('data: %s', $data).\PHP_EOL;
        echo \PHP_EOL;
        if (false !== ob_get_length()) {
            ob_flush();
        }
        flush();
    }
}
