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
use Mcp\Exception\InvalidArgumentException;

/**
 * Describes an argument that a prompt can accept.
 *
 * @phpstan-type PromptArgumentData array{
 *     name: string,
 *     description?: string,
 *     required?: bool,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class PromptArgument implements JsonSerializable
{
    /**
     * @param string      $name        the name of the argument
     * @param string|null $description a human-readable description of the argument
     * @param bool|null   $required    Whether this argument must be provided. Defaults to false per MCP spec if omitted.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?bool $required = null,
    ) {
    }

    /**
     * @param PromptArgumentData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in PromptArgument data.');
        }

        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            required: $data['required'] ?? null // Keep null if not present, MCP implies default false
        );
    }

    /**
     * @return PromptArgumentData
     */
    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }
        if (null !== $this->required) {
            $data['required'] = $this->required;
        }

        return $data;
    }
}
