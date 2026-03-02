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
 * A prompt or prompt template that the server offers.
 *
 * @phpstan-import-type PromptArgumentData from PromptArgument
 * @phpstan-import-type IconData from Icon
 *
 * @phpstan-type PromptData array{
 *     name: string,
 *     description?: string,
 *     arguments?: PromptArgumentData[],
 *     icons?: IconData[],
 *     _meta?: array<string, mixed>
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Prompt implements \JsonSerializable
{
    /**
     * @param string                $name        the name of the prompt or prompt template
     * @param ?string               $description an optional description of what this prompt provides
     * @param ?PromptArgument[]     $arguments   A list of arguments for templating. Null if not a template.
     * @param ?Icon[]               $icons       optional icons representing the prompt
     * @param ?array<string, mixed> $meta        Optional metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?array $arguments = null,
        public readonly ?array $icons = null,
        public readonly ?array $meta = null,
    ) {
        if (null !== $this->arguments) {
            foreach ($this->arguments as $arg) {
                if (!$arg instanceof PromptArgument) {
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
            $arguments = array_map(static fn (array $argData) => PromptArgument::fromArray($argData), $data['arguments']);
        }

        if (!empty($data['_meta']) && !\is_array($data['_meta'])) {
            throw new InvalidArgumentException('Invalid "_meta" in Prompt data.');
        }

        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            arguments: $arguments,
            icons: isset($data['icons']) && \is_array($data['icons']) ? array_map(Icon::fromArray(...), $data['icons']) : null,
            meta: isset($data['_meta']) ? $data['_meta'] : null
        );
    }

    /**
     * @return array{
     *     name: string,
     *     description?: string,
     *     arguments?: array<PromptArgument>,
     *     icons?: Icon[],
     *     _meta?: array<string, mixed>
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
        if (null !== $this->icons) {
            $data['icons'] = $this->icons;
        }
        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
