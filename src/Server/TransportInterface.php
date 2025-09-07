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
 */
interface TransportInterface
{
    /**
     * Initializes the transport.
     */
    public function initialize(): void;

    /**
     * Registers the callback that the Server will use to process incoming messages.
     * The transport must call this handler whenever a raw JSON-RPC message string is received.
     *
     * @param callable(string): void $handler The message processing callback.
     */
    public function setMessageHandler(callable $handler): void;


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
     */
    public function send(string $data): void;

    /**
     * Closes the transport and cleans up any resources.
     */
    public function close(): void;
}
