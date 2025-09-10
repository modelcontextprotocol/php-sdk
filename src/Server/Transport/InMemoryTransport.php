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

namespace Mcp\Server\Transport;

use Generator;
use Mcp\Server\TransportInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InMemoryTransport implements TransportInterface
{
    private bool $connected = true;

    /**
     * @param list<string> $messages
     */
    public function __construct(
        private readonly array $messages = [],
    ) {
    }

    public function initialize(): void
    {
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function receive(): Generator
    {
        yield from $this->messages;
        $this->connected = false;
    }

    public function send(string $data): void
    {
    }

    public function close(): void
    {
    }
}
