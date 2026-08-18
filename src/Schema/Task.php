<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;

/**
 * A durable handle on work that outlives the request that started it
 * (SEP-2663, the `io.modelcontextprotocol/tasks` extension).
 *
 * Flat on the wire in both places it appears: a `CreateTaskResult` is the
 * result *and* the task, and `tasks/get` answers with the task at the root.
 * There is no nested `task` key — a client reading `taskId` should not have to
 * know which of the two shapes it is holding.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class Task implements \JsonSerializable
{
    /**
     * @param string                                                                     $taskId         server-assigned, unique and durable
     * @param ?string                                                                    $statusMessage  human-readable progress, e.g. "42 of 100 rows"
     * @param ?int                                                                       $ttlMs          how long the task stays readable through `tasks/get`; null means no limit
     * @param ?int                                                                       $pollIntervalMs how long a client should wait between polls
     * @param mixed                                                                      $result         what the original request would have returned, once completed
     * @param ?Error                                                                     $error          a protocol-level failure, inlined when the status is `failed`
     * @param array<string, ElicitRequest|CreateSamplingMessageRequest|ListRootsRequest> $inputRequests  what the task is waiting for, when the status is `input_required`
     */
    public function __construct(
        public readonly string $taskId,
        public readonly TaskStatus $status = TaskStatus::Working,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $lastUpdatedAt = null,
        public readonly ?int $ttlMs = null,
        public readonly ?int $pollIntervalMs = null,
        public readonly ?string $statusMessage = null,
        public readonly mixed $result = null,
        public readonly ?Error $error = null,
        public readonly array $inputRequests = [],
    ) {
        if ('' === $this->taskId) {
            throw new InvalidArgumentException('A task must have a non-empty "taskId".');
        }

        // Both are milliseconds, and both are guidance a client acts on — a
        // fractional or negative one is not guidance, it is a bug on the wire.
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
    }

    /**
     * Reads a task back from its wire shape — what {@see self::jsonSerialize()}
     * produces, or a superset of it.
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

        $error = \is_array($data['error'] ?? null) && isset($data['error']['code'], $data['error']['message'])
            ? new Error(null, (int) $data['error']['code'], (string) $data['error']['message'], $data['error']['data'] ?? null)
            : null;

        return new self(
            $data['taskId'],
            $status,
            \is_string($data['createdAt'] ?? null) ? new \DateTimeImmutable($data['createdAt']) : null,
            \is_string($data['lastUpdatedAt'] ?? null) ? new \DateTimeImmutable($data['lastUpdatedAt']) : null,
            \is_int($data['ttlMs'] ?? null) ? $data['ttlMs'] : null,
            \is_int($data['pollIntervalMs'] ?? null) ? $data['pollIntervalMs'] : null,
            \is_string($data['statusMessage'] ?? null) ? $data['statusMessage'] : null,
            $data['result'] ?? null,
            $error,
            self::inputRequestsFrom($data['inputRequests'] ?? []),
        );
    }

    /**
     * The `inputRequests` map, from bare method/params pairs or full envelopes.
     *
     * @return array<string, ElicitRequest|CreateSamplingMessageRequest|ListRootsRequest>
     */
    private static function inputRequestsFrom(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $requests = [];

        foreach ($raw as $key => $envelope) {
            if (!\is_array($envelope) || !\is_string($envelope['method'] ?? null)) {
                continue;
            }

            $class = match ($envelope['method']) {
                ElicitRequest::getMethod() => ElicitRequest::class,
                CreateSamplingMessageRequest::getMethod() => CreateSamplingMessageRequest::class,
                ListRootsRequest::getMethod() => ListRootsRequest::class,
                default => null,
            };

            if (null === $class) {
                continue;
            }

            // On the wire the pair has no id — the map key is what answers are
            // keyed by — so one is supplied to satisfy the message parser.
            $requests[(string) $key] = $class::fromArray($envelope + ['jsonrpc' => MessageInterface::JSONRPC_VERSION, 'id' => 0]);
        }

        return $requests;
    }

    /**
     * A copy with a new status and whatever that status carries.
     *
     * @param array<string, ElicitRequest|CreateSamplingMessageRequest|ListRootsRequest> $inputRequests
     */
    public function with(
        TaskStatus $status,
        mixed $result = null,
        ?Error $error = null,
        array $inputRequests = [],
        ?string $statusMessage = null,
        ?\DateTimeImmutable $now = null,
    ): self {
        return new self(
            $this->taskId,
            $status,
            $this->createdAt,
            $now ?? new \DateTimeImmutable(),
            $this->ttlMs,
            $this->pollIntervalMs,
            $statusMessage ?? $this->statusMessage,
            $result ?? $this->result,
            $error ?? $this->error,
            [] !== $inputRequests ? $inputRequests : $this->inputRequests,
        );
    }

    /**
     * Whether the task is still readable, given its TTL.
     */
    public function isReadable(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->ttlMs || null === $this->createdAt) {
            return true;
        }

        $expiresAt = $this->createdAt->modify(\sprintf('+%d milliseconds', $this->ttlMs));

        return ($now ?? new \DateTimeImmutable()) < $expiresAt;
    }

    /**
     * The task's own fields, without anything only a detailed view carries.
     *
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
        ];

        if (null !== $this->createdAt) {
            $data['createdAt'] = $this->createdAt->format(\DATE_ATOM);
        }

        if (null !== $this->lastUpdatedAt) {
            $data['lastUpdatedAt'] = $this->lastUpdatedAt->format(\DATE_ATOM);
        }

        // Emitted even as null: absent would mean "the server said nothing
        // about how long this lives", and null means "as long as you like".
        $data['ttlMs'] = $this->ttlMs;

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

        if ([] !== $this->inputRequests) {
            $requests = [];
            foreach ($this->inputRequests as $key => $request) {
                // Values are bare method/params pairs, not messages: the client
                // keys answers by the map key. getParams() is protected, so the
                // envelope is built with a throwaway id and then discarded.
                $params = $request->withId(0)->jsonSerialize()['params'] ?? null;

                $requests[$key] = [
                    'method' => $request::getMethod(),
                    // An empty PHP array would encode as `[]`, not `{}`.
                    'params' => [] === $params || null === $params ? new \stdClass() : $params,
                ];
            }
            $data['inputRequests'] = $requests;
        }

        return $data;
    }
}
