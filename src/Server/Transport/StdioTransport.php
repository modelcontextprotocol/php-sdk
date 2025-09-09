<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Server\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Heavily inspired by https://jolicode.com/blog/mcp-the-open-protocol-that-turns-llm-chatbots-into-intelligent-agents.
 */
class StdioTransport implements TransportInterface
{
    /** @var array<string, array<callable>> */
    private array $listeners = [];

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function initialize(): void {}

    public function on(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed ...$args): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }
        foreach ($this->listeners[$event] as $listener) {
            $listener(...$args);
        }
    }

    public function send(string $data): void
    {
        $this->logger->debug('Sending data to client via StdioTransport.', ['data' => $data]);

        fwrite($this->output, $data . \PHP_EOL);
    }

    public function listen(): mixed
    {
        $this->logger->info('StdioTransport is listening for messages on STDIN...');

        while (!feof($this->input)) {
            $line = fgets($this->input);
            if ($line === false) {
                break;
            }

            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                $this->logger->debug('Received message on StdioTransport.', ['line' => $trimmedLine]);
                $this->emit('message', $trimmedLine);
            }
        }

        $this->logger->info('StdioTransport finished listening.');

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->input)) {
            fclose($this->input);
        }

        if (is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
