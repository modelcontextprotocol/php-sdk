<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema;

use JsonSerializable;

/**
 * Additional properties describing a Tool to clients.
 * NOTE: all properties in ToolAnnotations are hints.
 *
 * @phpstan-type ToolAnnotationsData array{
 *     title?: string,
 *     readOnlyHint?: bool,
 *     destructiveHint?: bool,
 *     idempotentHint?: bool,
 *     openWorldHint?: bool,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ToolAnnotations implements JsonSerializable
{
    /**
     * @param ?string $title           a human-readable title for the tool
     * @param ?bool   $readOnlyHint    if true, the tool does not modify its environment
     * @param ?bool   $destructiveHint If true, the tool may perform destructive updates to its environment. If false, the tool performs only additive updates.
     * @param ?bool   $idempotentHint  If true, calling the tool repeatedly with the same arguments will have no additional effect on the its environment. (This property is meaningful only when `readOnlyHint == false`)
     * @param ?bool   $openWorldHint   If true, this tool may interact with an "open world" of external entities. If false, the tool's domain of interaction is closed. For example, the world of a web search tool is open, whereas that of a memory tool is not.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?bool $readOnlyHint = null,
        public readonly ?bool $destructiveHint = null,
        public readonly ?bool $idempotentHint = null,
        public readonly ?bool $openWorldHint = null,
    ) {
    }

    /**
     * @param ToolAnnotationsData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['title'] ?? null,
            $data['readOnlyHint'] ?? null,
            $data['destructiveHint'] ?? null,
            $data['idempotentHint'] ?? null,
            $data['openWorldHint'] ?? null
        );
    }

    /**
     * @return ToolAnnotationsData
     */
    public function jsonSerialize(): array
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

        return $data;
    }
}
