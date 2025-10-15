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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Symfony\Component\Uid\Uuid;

/**
 * @template TResult
 *
 * @phpstan-type FiberReturn (Response<mixed>|Error)
 * @phpstan-type FiberResume (FiberReturn|null)
 * @phpstan-type FiberSuspend (
 *    array{type: 'notification', notification: \Mcp\Schema\JsonRpc\Notification}|
 *    array{type: 'request', request: \Mcp\Schema\JsonRpc\Request, timeout?: int}
 * )
 * @phpstan-type McpFiber \Fiber<null, FiberReturn, FiberReturn, FiberSuspend>
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
     * Send a message to the client immediately (bypassing session queue).
     *
     * Used for session resolution errors when no session is available.
     * The transport decides HOW to send based on context.
     *
     * @param array<string, mixed> $context Context about this message:
     *                                      - 'session_id': Uuid|null
     *                                      - 'type': 'response'|'request'|'notification'
     *                                      - 'status_code': int (HTTP status code for errors)
     */
    public function send(string $data, array $context): void;

    /**
     * Closes the transport and cleans up any resources.
     */
    public function close(): void;

    /**
     * Register callback for ALL incoming messages.
     *
     * The transport calls this whenever ANY message arrives, regardless of source.
     *
     * @param callable(string $message, ?Uuid $sessionId): void $listener
     */
    public function onMessage(callable $listener): void;

    /**
     * Register a listener for when a session is terminated.
     *
     * The transport calls this when a client disconnects or explicitly ends their session.
     *
     * @param callable(Uuid $sessionId): void $listener The callback function to execute when destroying a session
     */
    public function onSessionEnd(callable $listener): void;

    /**
     * Set a provider function to retrieve all queued outgoing messages.
     *
     * The transport calls this to retrieve all queued messages for a session.
     *
     * @param callable(Uuid $sessionId): array<int, array{message: string, context: array<string, mixed>}> $provider
     */
    public function setOutgoingMessagesProvider(callable $provider): void;

    /**
     * Set a provider function to retrieve all pending server-initiated requests.
     *
     * The transport calls this to decide if it should wait for a client response before resuming a Fiber.
     *
     * @param callable(Uuid $sessionId): array<int, array<string, mixed>> $provider
     */
    public function setPendingRequestsProvider(callable $provider): void;

    /**
     * Set a finder function to check for a specific client response.
     *
     * @param callable(int, Uuid):FiberResume $finder
     */
    public function setResponseFinder(callable $finder): void;

    /**
     * Set a handler for processing values yielded from a suspended Fiber.
     *
     * The transport calls this to let the Protocol handle new requests/notifications
     * that are yielded from a Fiber's execution.
     *
     * @param callable(FiberSuspend|null, ?Uuid $sessionId): void $handler
     */
    public function setFiberYieldHandler(callable $handler): void;

    /**
     * @param McpFiber $fiber
     */
    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void;

    /**
     * Set the session ID for the current transport context.
     *
     * @param Uuid|null $sessionId The session ID, or null to clear
     */
    public function setSessionId(?Uuid $sessionId): void;
}
