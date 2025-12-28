<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Mcp\Client\Session\ClientSessionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;

/**
 * Interface for client transports that communicate with MCP servers.
 *
 * The transport owns its execution loop and manages all blocking operations.
 * The client delegates completely to the transport for I/O.
 *
 * @phpstan-type FiberReturn (Response<mixed>|Error)
 * @phpstan-type FiberResume (Response<mixed>|Error)
 * @phpstan-type FiberSuspend array{type: 'await_response', request_id: int, timeout: int}
 * @phpstan-type McpFiber \Fiber<null, FiberResume, FiberReturn, FiberSuspend>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface TransportInterface
{
    /**
     * Connect to the MCP server and perform initialization handshake.
     *
     * This method blocks until:
     * - Initialization completes successfully
     * - Connection fails (throws ConnectionException)
     *
     * @throws \Mcp\Exception\ConnectionException
     */
    public function connectAndInitialize(): void;

    /**
     * Send a message to the server immediately.
     *
     * @param string $data JSON-encoded message
     */
    public function send(string $data): void;

    /**
     * Run a request fiber to completion.
     *
     * The transport starts the fiber, runs its internal loop, and resumes
     * the fiber when a response arrives or timeout occurs.
     *
     * During the loop, the transport checks session for progress data and
     * executes the callback if provided.
     *
     * @param McpFiber                                                                $fiber      The fiber to execute
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     *
     * @return Response<array<string, mixed>>|Error The response or error
     */
    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error;

    /**
     * Close the transport and clean up resources.
     */
    public function close(): void;

    /**
     * Register callback for initialization handshake.
     *
     * The callback should return a Fiber that performs the initialization.
     *
     * @param callable(): mixed $callback
     */
    public function onInitialize(callable $callback): void;

    /**
     * Register callback for incoming messages from server.
     *
     * @param callable(string $message): void $callback
     */
    public function onMessage(callable $callback): void;

    /**
     * Register callback for transport errors.
     *
     * @param callable(\Throwable $error): void $callback
     */
    public function onError(callable $callback): void;

    /**
     * Register callback for when connection closes.
     *
     * @param callable(string $reason): void $callback
     */
    public function onClose(callable $callback): void;

    /**
     * Set the client session for state management.
     */
    public function setSession(ClientSessionInterface $session): void;
}
