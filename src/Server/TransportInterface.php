<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface TransportInterface
{
    /**
     * Initializes the transport.
     */
    public function initialize(): void;

    /**
     * Registers an event listener for the specified event.
     *
     * @param string $event The event name to listen for
     * @param callable $listener The callback function to execute when the event occurs
     */
    public function on(string $event, callable $listener): void;

    /**
     * Triggers an event and executes all registered listeners.
     *
     * @param string $event The event name to emit
     * @param mixed ...$args Variable number of arguments to pass to the listeners
     */
    public function emit(string $event, mixed ...$args): void;

    /**
     * Starts the transport's execution process.
     *
     * - For a blocking transport like STDIO, this method will run a continuous loop.
     * - For a single-request transport like HTTP, this will process the request
     *   and return a result (e.g., a PSR-7 Response) to be sent to the client.
     *
     * @return mixed The result of the transport's execution, if any.
     */
    public function listen(): mixed;

    /**
     * Sends a raw JSON-RPC message string back to the client.
     *
     * @param string $data The JSON-RPC message string to send
     */
    public function send(string $data): void;

    /**
     * Closes the transport and cleans up any resources.
     *
     * This method should be called when the transport is no longer needed.
     * It should clean up any resources and close any connections.
     */
    public function close(): void;
}
