<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Session;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Interface for client session state management.
 *
 * Tracks pending requests, stores responses, and manages message queues.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface ClientSessionInterface
{
    /**
     * Get the session ID.
     */
    public function getId(): Uuid;

    /**
     * Get the next request ID for outgoing requests.
     */
    public function nextRequestId(): int;

    /**
     * Add a pending request to track.
     *
     * @param int $requestId The request ID
     * @param int $timeout   Timeout in seconds
     */
    public function addPendingRequest(int $requestId, int $timeout): void;

    /**
     * Remove a pending request.
     */
    public function removePendingRequest(int $requestId): void;

    /**
     * Get all pending requests.
     *
     * @return array<int, array{request_id: int, timestamp: int, timeout: int}>
     */
    public function getPendingRequests(): array;

    /**
     * Store a received response.
     *
     * @param int                  $requestId    The request ID
     * @param array<string, mixed> $responseData The raw response data
     */
    public function storeResponse(int $requestId, array $responseData): void;

    /**
     * Check and consume a response for a request ID.
     *
     * @return Response<array<string, mixed>>|Error|null
     */
    public function consumeResponse(int $requestId): Response|Error|null;

    /**
     * Queue an outgoing message.
     *
     * @param string               $message JSON-encoded message
     * @param array<string, mixed> $context Message context
     */
    public function queueOutgoing(string $message, array $context): void;

    /**
     * Get and clear all queued outgoing messages.
     *
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    public function consumeOutgoingMessages(): array;

    /**
     * Set initialization state.
     */
    public function setInitialized(bool $initialized): void;

    /**
     * Check if session is initialized.
     */
    public function isInitialized(): bool;

    /**
     * Store server capabilities and info from initialization.
     *
     * @param array<string, mixed> $serverInfo
     */
    public function setServerInfo(array $serverInfo): void;

    /**
     * Get stored server info.
     *
     * @return array<string, mixed>|null
     */
    public function getServerInfo(): ?array;

    /**
     * Store progress data received from a notification.
     *
     * @param string      $token    The progress token
     * @param float       $progress Current progress value
     * @param float|null  $total    Total progress value (if known)
     * @param string|null $message  Progress message
     */
    public function storeProgress(string $token, float $progress, ?float $total, ?string $message): void;

    /**
     * Consume all pending progress updates.
     *
     * @return array<int, array{token: string, progress: float, total: ?float, message: ?string}>
     */
    public function consumeProgressUpdates(): array;
}
