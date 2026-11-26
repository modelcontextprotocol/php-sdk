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

/**
 * A snapshot of a task returned by the server.
 *
 * @author OpenAI
 */
final class Task implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null    $result        the original method's result, available on completion
     * @param array<string, mixed>|null    $error         the JSON-RPC error, available on failure
     * @param array<array-key, mixed>|null $inputRequests server-assigned keys mapped to method/params pairs
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $lastUpdatedAt,
        public readonly ?int $ttlMs,
        public readonly ?string $statusMessage = null,
        public readonly ?int $pollIntervalMs = null,
        public readonly ?array $result = null,
        public readonly ?array $error = null,
        public readonly ?array $inputRequests = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['taskId', 'status', 'createdAt', 'lastUpdatedAt'] as $member) {
            if (!\is_string($data[$member] ?? null)) {
                throw new InvalidArgumentException(\sprintf('Missing or invalid "%s" in task data.', $member));
            }
        }

        if (!\array_key_exists('ttlMs', $data) || null !== $data['ttlMs'] && !\is_int($data['ttlMs'])) {
            throw new InvalidArgumentException('Missing or invalid "ttlMs" in task data.');
        }

        if (isset($data['statusMessage']) && !\is_string($data['statusMessage'])) {
            throw new InvalidArgumentException('Invalid "statusMessage" in task data.');
        }

        if (isset($data['pollIntervalMs']) && !\is_int($data['pollIntervalMs'])) {
            throw new InvalidArgumentException('Invalid "pollIntervalMs" in task data.');
        }

        foreach (['result', 'error', 'inputRequests'] as $member) {
            if (isset($data[$member]) && !\is_array($data[$member]) && !$data[$member] instanceof \stdClass) {
                throw new InvalidArgumentException(\sprintf('Invalid "%s" in task data.', $member));
            }
        }

        return new self(
            $data['taskId'],
            $data['status'],
            $data['createdAt'],
            $data['lastUpdatedAt'],
            $data['ttlMs'],
            $data['statusMessage'] ?? null,
            $data['pollIntervalMs'] ?? null,
            isset($data['result']) ? (array) $data['result'] : null,
            isset($data['error']) ? (array) $data['error'] : null,
            isset($data['inputRequests']) ? (array) $data['inputRequests'] : null,
        );
    }

    /**
     * @return array{
     *     taskId: string,
     *     status: string,
     *     createdAt: string,
     *     lastUpdatedAt: string,
     *     ttlMs: int|null,
     *     statusMessage?: string,
     *     pollIntervalMs?: int,
     *     result?: \stdClass,
     *     error?: \stdClass,
     *     inputRequests?: \stdClass,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'lastUpdatedAt' => $this->lastUpdatedAt,
            'ttlMs' => $this->ttlMs,
        ];

        if (null !== $this->statusMessage) {
            $data['statusMessage'] = $this->statusMessage;
        }

        if (null !== $this->pollIntervalMs) {
            $data['pollIntervalMs'] = $this->pollIntervalMs;
        }

        foreach (['result', 'error', 'inputRequests'] as $member) {
            if (null !== $this->$member) {
                $data[$member] = (object) $this->$member;
            }
        }

        return $data;
    }
}
