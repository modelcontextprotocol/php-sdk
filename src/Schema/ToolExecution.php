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
 * Execution-related properties for a tool.
 *
 * Only on protocol revision 2025-11-25.
 *
 * @phpstan-type ToolExecutionData array{
 *     taskSupport?: 'forbidden'|'optional'|'required',
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ToolExecution implements \JsonSerializable
{
    public function __construct(
        public readonly ?TaskSupport $taskSupport = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $taskSupport = null;
        if (isset($data['taskSupport'])) {
            $taskSupport = \is_string($data['taskSupport']) ? TaskSupport::tryFrom($data['taskSupport']) : null;
            if (null === $taskSupport) {
                throw new InvalidArgumentException('Invalid "taskSupport" in ToolExecution data.');
            }
        }

        return new self(
            taskSupport: $taskSupport,
        );
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public function jsonSerialize(): array|\stdClass
    {
        $data = [];

        if (null !== $this->taskSupport) {
            $data['taskSupport'] = $this->taskSupport->value;
        }

        return [] === $data ? new \stdClass() : $data;
    }
}
