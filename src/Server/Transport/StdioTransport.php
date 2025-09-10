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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Heavily inspired by https://jolicode.com/blog/mcp-the-open-protocol-that-turns-llm-chatbots-into-intelligent-agents.
 */
class StdioTransport implements TransportInterface
{
    private string $buffer = '';

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function initialize(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function receive(): Generator
    {
        $line = fgets($this->input);

        $this->logger->debug('Received message on StdioTransport.', [
            'line' => $line,
        ]);

        if (false === $line) {
            return;
        }
        $this->buffer .= rtrim($line).\PHP_EOL;
        if (str_contains($this->buffer, \PHP_EOL)) {
            $lines = explode(\PHP_EOL, $this->buffer);
            $this->buffer = array_pop($lines);

            yield from $lines;
        }
    }

    public function send(string $data): void
    {
        $this->logger->debug('Sending data to client via StdioTransport.', ['data' => $data]);

        fwrite($this->output, $data.\PHP_EOL);
    }

    public function close(): void
    {
    }
}
