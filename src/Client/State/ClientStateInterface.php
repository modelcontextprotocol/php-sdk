<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\State;

use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;

/**
 * Interface for client state management.
 *
 * Tracks pending requests, stores responses, and manages runtime state
 * for the client's connection to a server. This is ephemeral state that
 * exists only for the lifetime of the connection.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface ClientStateInterface
{
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
     * Set initialization state.
     */
    public function setInitialized(bool $initialized): void;

    /**
     * Check if connection is initialized.
     */
    public function isInitialized(): bool;

    /**
     * Store the server info from initialization.
     */
    public function setServerInfo(Implementation $serverInfo): void;

    /**
     * Get the server info from initialization.
     */
    public function getServerInfo(): ?Implementation;

    /**
     * Store the server instructions from initialization.
     */
    public function setInstructions(?string $instructions): void;

    /**
     * Get the server instructions from initialization.
     */
    public function getInstructions(): ?string;

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
