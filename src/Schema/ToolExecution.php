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
use Mcp\Schema\Enum\TaskSupport;

/**
 * Execution-related properties of a Tool.
 *
 * @phpstan-type ToolExecutionData array{
 *     taskSupport?: 'forbidden'|'optional'|'required',
 * }
 */
class ToolExecution implements \JsonSerializable
{
    /**
     * @param ?TaskSupport $taskSupport whether the tool can be called as a task; absent means {@see TaskSupport::Forbidden}
     */
    public function __construct(
        public readonly ?TaskSupport $taskSupport = null,
    ) {
    }

    /**
     * @param ToolExecutionData $data
     */
    public static function fromArray(array $data): self
    {
        $taskSupport = null;
        if (isset($data['taskSupport'])) {
            $taskSupport = \is_string($data['taskSupport']) ? TaskSupport::tryFrom($data['taskSupport']) : null;
            if (null === $taskSupport) {
                throw new InvalidArgumentException('Invalid "taskSupport" in ToolExecution data; expected "forbidden", "optional" or "required".');
            }
        }

        return new self($taskSupport);
    }

    /**
     * @return ToolExecutionData|\stdClass an empty execution encodes as `{}`, not `[]`
     */
    public function jsonSerialize(): array|\stdClass
    {
        if (null === $this->taskSupport) {
            return new \stdClass();
        }

        return ['taskSupport' => $this->taskSupport->value];
    }
}
