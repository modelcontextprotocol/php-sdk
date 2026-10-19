<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Client;

use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;

/**
 * Keeps every message the server sent, as it arrived on the wire.
 *
 * Lets a test compare what the server said with what the client made of it.
 * Implements only {@see TransportInterface}, so it hides the header callbacks
 * of a 2026-07-28 HTTP transport: wrap transports speaking 2025-11-25.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<\stdClass> */
    private array $received = [];

    public function __construct(
        private readonly TransportInterface $transport,
    ) {
    }

    /**
     * Decoded into objects, so an empty JSON object stays distinguishable
     * from an empty list.
     *
     * @return list<\stdClass> messages, oldest first
     */
    public function received(): array
    {
        return $this->received;
    }

    public function clear(): void
    {
        $this->received = [];
    }

    public function connect(): void
    {
        $this->transport->connect();
    }

    public function send(string $data): void
    {
        $this->transport->send($data);
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        return $this->transport->runRequest($fiber, $onProgress);
    }

    public function close(): void
    {
        $this->transport->close();
    }

    public function onInitialize(callable $callback): void
    {
        $this->transport->onInitialize($callback);
    }

    public function onMessage(callable $callback): void
    {
        $this->transport->onMessage(function (string $message) use ($callback): void {
            $decoded = json_decode($message);
            if ($decoded instanceof \stdClass) {
                $this->received[] = $decoded;
            }

            $callback($message);
        });
    }

    public function onError(callable $callback): void
    {
        $this->transport->onError($callback);
    }

    public function onClose(callable $callback): void
    {
        $this->transport->onClose($callback);
    }

    public function setState(ClientStateInterface $state): void
    {
        $this->transport->setState($state);
    }
}
