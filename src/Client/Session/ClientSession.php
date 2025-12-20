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
 * In-memory client session implementation.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ClientSession implements ClientSessionInterface
{
    private Uuid $id;
    private int $requestIdCounter = 1;
    private bool $initialized = false;

    /** @var array<string, mixed>|null */
    private ?array $serverInfo = null;

    /** @var array<int, array{request_id: int, timestamp: int, timeout: int}> */
    private array $pendingRequests = [];

    /** @var array<int, array<string, mixed>> */
    private array $responses = [];

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $outgoingQueue = [];

    /** @var array<int, array{token: string, progress: float, total: ?float, message: ?string}> */
    private array $progressUpdates = [];

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function nextRequestId(): int
    {
        return $this->requestIdCounter++;
    }

    public function addPendingRequest(int $requestId, int $timeout): void
    {
        $this->pendingRequests[$requestId] = [
            'request_id' => $requestId,
            'timestamp' => time(),
            'timeout' => $timeout,
        ];
    }

    public function removePendingRequest(int $requestId): void
    {
        unset($this->pendingRequests[$requestId]);
    }

    public function getPendingRequests(): array
    {
        return $this->pendingRequests;
    }

    public function storeResponse(int $requestId, array $responseData): void
    {
        $this->responses[$requestId] = $responseData;
    }

    public function consumeResponse(int $requestId): Response|Error|null
    {
        if (!isset($this->responses[$requestId])) {
            return null;
        }

        $data = $this->responses[$requestId];
        unset($this->responses[$requestId]);
        $this->removePendingRequest($requestId);

        if (isset($data['error'])) {
            return Error::fromArray($data);
        }

        return Response::fromArray($data);
    }

    public function queueOutgoing(string $message, array $context): void
    {
        $this->outgoingQueue[] = [
            'message' => $message,
            'context' => $context,
        ];
    }

    public function consumeOutgoingMessages(): array
    {
        $messages = $this->outgoingQueue;
        $this->outgoingQueue = [];

        return $messages;
    }

    public function setInitialized(bool $initialized): void
    {
        $this->initialized = $initialized;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function setServerInfo(array $serverInfo): void
    {
        $this->serverInfo = $serverInfo;
    }

    public function getServerInfo(): ?array
    {
        return $this->serverInfo;
    }

    public function storeProgress(string $token, float $progress, ?float $total, ?string $message): void
    {
        $this->progressUpdates[] = [
            'token' => $token,
            'progress' => $progress,
            'total' => $total,
            'message' => $message,
        ];
    }

    public function consumeProgressUpdates(): array
    {
        $updates = $this->progressUpdates;
        $this->progressUpdates = [];

        return $updates;
    }
}
