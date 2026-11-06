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

/**
 * Additional properties describing a Tool to clients.
 *
 * @phpstan-type ToolAnnotationsData array{
 *     title?: string,
 *     readOnlyHint?: bool,
 *     destructiveHint?: bool,
 *     idempotentHint?: bool,
 *     openWorldHint?: bool,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ToolAnnotations implements \JsonSerializable
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?bool $readOnlyHint = null,
        public readonly ?bool $destructiveHint = null,
        public readonly ?bool $idempotentHint = null,
        public readonly ?bool $openWorldHint = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $title = null;
        if (isset($data['title'])) {
            if (!\is_string($data['title'])) {
                throw new InvalidArgumentException('Invalid "title" in ToolAnnotations data.');
            }
            $title = $data['title'];
        }
        $readOnlyHint = null;
        if (isset($data['readOnlyHint'])) {
            if (!\is_bool($data['readOnlyHint'])) {
                throw new InvalidArgumentException('Invalid "readOnlyHint" in ToolAnnotations data.');
            }
            $readOnlyHint = $data['readOnlyHint'];
        }
        $destructiveHint = null;
        if (isset($data['destructiveHint'])) {
            if (!\is_bool($data['destructiveHint'])) {
                throw new InvalidArgumentException('Invalid "destructiveHint" in ToolAnnotations data.');
            }
            $destructiveHint = $data['destructiveHint'];
        }
        $idempotentHint = null;
        if (isset($data['idempotentHint'])) {
            if (!\is_bool($data['idempotentHint'])) {
                throw new InvalidArgumentException('Invalid "idempotentHint" in ToolAnnotations data.');
            }
            $idempotentHint = $data['idempotentHint'];
        }
        $openWorldHint = null;
        if (isset($data['openWorldHint'])) {
            if (!\is_bool($data['openWorldHint'])) {
                throw new InvalidArgumentException('Invalid "openWorldHint" in ToolAnnotations data.');
            }
            $openWorldHint = $data['openWorldHint'];
        }

        return new self(
            title: $title,
            readOnlyHint: $readOnlyHint,
            destructiveHint: $destructiveHint,
            idempotentHint: $idempotentHint,
            openWorldHint: $openWorldHint,
        );
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public function jsonSerialize(): array|\stdClass
    {
        $data = [];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->readOnlyHint) {
            $data['readOnlyHint'] = $this->readOnlyHint;
        }

        if (null !== $this->destructiveHint) {
            $data['destructiveHint'] = $this->destructiveHint;
        }

        if (null !== $this->idempotentHint) {
            $data['idempotentHint'] = $this->idempotentHint;
        }

        if (null !== $this->openWorldHint) {
            $data['openWorldHint'] = $this->openWorldHint;
        }

        return [] === $data ? new \stdClass() : $data;
    }
}
