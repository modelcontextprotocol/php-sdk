<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Tasks;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Exception\LogicException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * A durable handle on work that outlives the request that started it
 * (SEP-2663, the `io.modelcontextprotocol/tasks` extension).
 *
 * Flat on the wire in both places it appears: a `CreateTaskResult` is the
 * result *and* the task, and `tasks/get` answers with the task at the root.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Task implements ResultInterface
{
    /**
     * @param string                $taskId         server-assigned, unique and durable
     * @param ?int                  $ttlMs          how long the task stays readable through `tasks/get`; null means no limit
     * @param ?int                  $pollIntervalMs how long a client should wait between polls
     * @param ?string               $statusMessage  human-readable progress, e.g. "42 of 100 rows"
     * @param ?array<string, mixed> $result         what the original request would have returned, once completed
     * @param ?Error                $error          a protocol-level failure, inlined when the status is `failed`
     * @param ?string               $owner          who created the task, checked on every access; never sent on the wire
     */
    public function __construct(
        public readonly string $taskId,
        public readonly TaskStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $lastUpdatedAt,
        public readonly ?int $ttlMs = null,
        public readonly ?int $pollIntervalMs = null,
        public readonly ?string $statusMessage = null,
        public readonly ?array $result = null,
        public readonly ?Error $error = null,
        public readonly ?string $owner = null,
    ) {
        if ('' === $this->taskId) {
            throw new InvalidArgumentException('A task must have a non-empty "taskId".');
        }

        if (null !== $this->ttlMs && $this->ttlMs <= 0) {
            throw new InvalidArgumentException(\sprintf('A task "ttlMs" must be a positive number of milliseconds or null, got %d.', $this->ttlMs));
        }

        if (null !== $this->pollIntervalMs && $this->pollIntervalMs <= 0) {
            throw new InvalidArgumentException(\sprintf('A task "pollIntervalMs" must be a positive number of milliseconds, got %d.', $this->pollIntervalMs));
        }

        if (TaskStatus::Failed === $this->status && null === $this->error) {
            throw new InvalidArgumentException('A failed task must carry the error that stopped it.');
        }

        if (null !== $this->error && TaskStatus::Failed !== $this->status) {
            throw new InvalidArgumentException('Only a failed task carries an "error"; a tool that ran and reported a problem is completed with "isError" on its result.');
        }

        if (TaskStatus::Completed === $this->status && null === $this->result) {
            throw new InvalidArgumentException('A completed task must carry the "result" the request would have returned.');
        }
    }

    /**
     * Reads a task back from its wire shape, or from the superset a store keeps.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the data does not describe a task
     */
    public static function fromArray(array $data): self
    {
        if (!\is_string($data['taskId'] ?? null) || '' === $data['taskId']) {
            throw new InvalidArgumentException('Missing or invalid "taskId" in Task data.');
        }

        $status = TaskStatus::tryFrom(\is_string($data['status'] ?? null) ? $data['status'] : '');
        if (null === $status) {
            throw new InvalidArgumentException(\sprintf('Missing or invalid "status" in Task data for task "%s".', $data['taskId']));
        }

        if (!\is_string($data['createdAt'] ?? null) || !\is_string($data['lastUpdatedAt'] ?? null)) {
            throw new InvalidArgumentException(\sprintf('Missing or invalid "createdAt" or "lastUpdatedAt" in Task data for task "%s".', $data['taskId']));
        }

        $error = \is_array($data['error'] ?? null) && isset($data['error']['code'], $data['error']['message'])
            ? new Error(null, (int) $data['error']['code'], (string) $data['error']['message'], $data['error']['data'] ?? null)
            : null;

        return new self(
            $data['taskId'],
            $status,
            new \DateTimeImmutable($data['createdAt']),
            new \DateTimeImmutable($data['lastUpdatedAt']),
            \is_int($data['ttlMs'] ?? null) ? $data['ttlMs'] : null,
            \is_int($data['pollIntervalMs'] ?? null) ? $data['pollIntervalMs'] : null,
            \is_string($data['statusMessage'] ?? null) ? $data['statusMessage'] : null,
            \is_array($data['result'] ?? null) ? $data['result'] : null,
            $error,
            \is_string($data['owner'] ?? null) ? $data['owner'] : null,
        );
    }

    /**
     * A copy moved to $status, carrying only what that status carries.
     *
     * @param ?array<string, mixed> $result
     *
     * @throws LogicException when the task already reached a terminal status
     */
    public function with(
        TaskStatus $status,
        \DateTimeImmutable $now,
        ?array $result = null,
        ?Error $error = null,
        ?string $statusMessage = null,
    ): self {
        if ($this->status->isTerminal()) {
            throw new LogicException(\sprintf('Task "%s" is already %s and cannot become %s.', $this->taskId, $this->status->value, $status->value));
        }

        return new self(
            $this->taskId,
            $status,
            $this->createdAt,
            $now,
            $this->ttlMs,
            $this->pollIntervalMs,
            $statusMessage ?? $this->statusMessage,
            TaskStatus::Completed === $status ? $result : null,
            TaskStatus::Failed === $status ? $error : null,
            $this->owner,
        );
    }

    /**
     * Whether the task is still readable at $now, given its TTL.
     */
    public function isReadable(\DateTimeImmutable $now): bool
    {
        if (null === $this->ttlMs) {
            return true;
        }

        return $now < $this->createdAt->modify(\sprintf('+%d milliseconds', $this->ttlMs));
    }

    /**
     * The task's own fields, without anything its status carries.
     *
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'lastUpdatedAt' => $this->lastUpdatedAt->format(\DATE_ATOM),
            // Emitted even as null: null means "unlimited", absent would say nothing.
            'ttlMs' => $this->ttlMs,
        ];

        if (null !== $this->pollIntervalMs) {
            $data['pollIntervalMs'] = $this->pollIntervalMs;
        }

        if (null !== $this->statusMessage) {
            $data['statusMessage'] = $this->statusMessage;
        }

        return $data;
    }

    /**
     * The envelope plus whatever the current status carries.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->toEnvelope();

        if (TaskStatus::Completed === $this->status) {
            $data['result'] = $this->result;
        }

        if (null !== $this->error) {
            $error = ['code' => $this->error->code, 'message' => $this->error->message];

            if (null !== $this->error->data) {
                $error['data'] = $this->error->data;
            }

            $data['error'] = $error;
        }

        return $data;
    }
}
