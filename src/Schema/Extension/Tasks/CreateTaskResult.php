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
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's initial task snapshot in place of a synchronous result.
 *
 * @author OpenAI
 */
final class CreateTaskResult implements ResultInterface
{
    public const RESULT_TYPE = 'task';

    /**
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly Task $task,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (self::RESULT_TYPE !== ($data['resultType'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid "resultType" in task creation result.');
        }

        if (isset($data['_meta']) && !\is_array($data['_meta']) && !$data['_meta'] instanceof \stdClass) {
            throw new InvalidArgumentException('Invalid "_meta" in task creation result.');
        }

        return new self(Task::fromArray($data), isset($data['_meta']) ? (array) $data['_meta'] : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['resultType' => self::RESULT_TYPE, ...$this->task->jsonSerialize()];

        if (null !== $this->meta) {
            $data['_meta'] = (object) $this->meta;
        }

        return $data;
    }
}
