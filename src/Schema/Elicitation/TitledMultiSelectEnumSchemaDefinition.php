<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Elicitation;

use Mcp\Exception\InvalidArgumentException;

/**
 * Schema definition for multi-select enum fields with titled options (SEP-1330).
 *
 * Produces: {"type": "array", "items": {"anyOf": [{"const": "value", "title": "Label"}, ...]}}
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1330
 */
final class TitledMultiSelectEnumSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string                                    $title       Human-readable title for the field
     * @param list<array{const: string, title: string}> $anyOf       Array of const/title pairs
     * @param string|null                               $description Optional description/help text
     * @param string[]|null                             $default     Optional default selected values (must be subset of anyOf consts)
     * @param int|null                                  $minItems    Optional minimum number of selections
     * @param int|null                                  $maxItems    Optional maximum number of selections
     */
    public function __construct(
        string $title,
        public readonly array $anyOf,
        ?string $description = null,
        public readonly ?array $default = null,
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
    ) {
        parent::__construct($title, $description);

        if ([] === $anyOf) {
            throw new InvalidArgumentException('anyOf array must not be empty.');
        }

        $consts = [];
        foreach ($anyOf as $item) {
            if (!isset($item['const']) || !\is_string($item['const'])) {
                throw new InvalidArgumentException('Each anyOf item must have a string "const" property.');
            }
            if (!isset($item['title']) || !\is_string($item['title'])) {
                throw new InvalidArgumentException('Each anyOf item must have a string "title" property.');
            }
            $consts[] = $item['const'];
        }

        if (null !== $minItems && $minItems < 0) {
            throw new InvalidArgumentException('minItems must be non-negative.');
        }

        if (null !== $maxItems && $maxItems < 0) {
            throw new InvalidArgumentException('maxItems must be non-negative.');
        }

        if (null !== $minItems && null !== $maxItems && $minItems > $maxItems) {
            throw new InvalidArgumentException('minItems cannot be greater than maxItems.');
        }

        if (null !== $default) {
            foreach ($default as $value) {
                if (!\in_array($value, $consts, true)) {
                    throw new InvalidArgumentException(\sprintf('Default value "%s" is not in the anyOf const values.', $value));
                }
            }
        }
    }

    /**
     * @param array{
     *     title: string,
     *     items: array{anyOf: list<array{const: string, title: string}>},
     *     description?: string,
     *     default?: string[],
     *     minItems?: int,
     *     maxItems?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::validateTitle($data, 'titled multi-select enum');

        if (!isset($data['items']['anyOf']) || !\is_array($data['items']['anyOf'])) {
            throw new InvalidArgumentException('Missing or invalid "items.anyOf" for titled multi-select enum schema definition.');
        }

        return new self(
            title: $data['title'],
            anyOf: $data['items']['anyOf'],
            description: $data['description'] ?? null,
            default: $data['default'] ?? null,
            minItems: isset($data['minItems']) ? (int) $data['minItems'] : null,
            maxItems: isset($data['maxItems']) ? (int) $data['maxItems'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->buildBaseJson('array');
        $data['items'] = [
            'anyOf' => $this->anyOf,
        ];

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        if (null !== $this->minItems) {
            $data['minItems'] = $this->minItems;
        }

        if (null !== $this->maxItems) {
            $data['maxItems'] = $this->maxItems;
        }

        return $data;
    }
}
