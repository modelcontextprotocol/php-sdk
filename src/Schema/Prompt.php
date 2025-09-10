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
 * A prompt or prompt template that the server offers.
 *
 * @phpstan-import-type PromptArgumentData from PromptArgument
 *
 * @phpstan-type PromptData array{
 *     name: string,
 *     description?: string,
 *     arguments?: PromptArgumentData[],
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Prompt implements JsonSerializable
{
    /**
     * @param string                $name        the name of the prompt or prompt template
     * @param string|null           $description an optional description of what this prompt provides
     * @param PromptArgument[]|null $arguments   A list of arguments for templating. Null if not a template.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?array $arguments = null,
    ) {
        if (null !== $this->arguments) {
            foreach ($this->arguments as $arg) {
                if (!($arg instanceof PromptArgument)) {
                    throw new InvalidArgumentException('All items in Prompt "arguments" must be PromptArgument instances.');
                }
            }
        }
    }

    /**
     * @param PromptData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in Prompt data.');
        }
        $arguments = null;
        if (isset($data['arguments']) && \is_array($data['arguments'])) {
            $arguments = array_map(fn (array $argData): PromptArgument => PromptArgument::fromArray($argData), $data['arguments']);
        }

        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            arguments: $arguments
        );
    }

    /**
     * @return array{
     *     name: string,
     *     description?: string,
     *     arguments?: array<PromptArgument>
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }
        if (null !== $this->arguments) {
            $data['arguments'] = $this->arguments;
        }

        return $data;
    }
}
