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
 * @template TResult
 *
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
     * Register callback for ALL incoming messages.
     *
     * The transport calls this whenever ANY message arrives, regardless of source.
     *
     * @param callable(string $message, ?Uuid $sessionId): void $listener
     */
    public function onMessage(callable $listener): void;

    /**
     * Starts the transport's execution process.
     *
     * - For a blocking transport like STDIO, this method will run a continuous loop.
     * - For a single-request transport like HTTP, this will process the request
     *   and return a result (e.g., a PSR-7 Response) to be sent to the client.
     *
     * @return TResult the result of the transport's execution, if any
     */
    public function listen(): mixed;

    /**
     * Send a message to the client.
     *
     * The transport decides HOW to send based on context
     *
     * @param array<string, mixed> $context Context about this message:
     *                                      - 'session_id': Uuid|null
     *                                      - 'type': 'response'|'request'|'notification'
     *                                      - 'in_reply_to': int|string|null (ID of request this responds to)
     *                                      - 'expects_response': bool (if this is a request needing response)
     */
    public function send(string $data, array $context): void;

    /**
     * Register callback for session termination.
     *
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
