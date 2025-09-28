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

use Symfony\Component\Uid\Uuid;

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
     * Registers a callback that will be invoked whenever the transport receives an incoming message.
     *
     * @param callable(string $message, ?Uuid $sessionId): void $listener The callback function to execute when the message occurs
     */
    public function onMessage(callable $listener): void;

    /**
     * Starts the transport's execution process.
     *
     * - For a blocking transport like STDIO, this method will run a continuous loop.
     * - For a single-request transport like HTTP, this will process the request
     *   and return a result (e.g., a PSR-7 Response) to be sent to the client.
     *
     * @return mixed the result of the transport's execution, if any
     */
    public function listen(): mixed;

    /**
     * Sends a raw JSON-RPC message string back to the client.
     *
     * @param string               $data    The JSON-RPC message string to send
     * @param array<string, mixed> $context The context of the message
     */
    public function send(string $data, array $context): void;

    /**
     * Registers a callback that will be invoked when a session needs to be destroyed.
     * This can happen when a client disconnects or explicitly ends their session.
     *
     * @param callable(Uuid $sessionId): void $listener The callback function to execute when destroying a session
     */
    public function onSessionEnd(callable $listener): void;

    /**
     * Closes the transport and cleans up any resources.
     *
     * This method should be called when the transport is no longer needed.
     * It should clean up any resources and close any connections.
     */
    public function close(): void;
}
