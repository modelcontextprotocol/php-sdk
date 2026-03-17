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
 * Schema definition for multi-select enum fields without titles (SEP-1330).
 *
 * Produces: {"type": "array", "items": {"type": "string", "enum": [...]}}
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1330
 */
final class MultiSelectEnumSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string        $title       Human-readable title for the field
     * @param string[]      $enum        Array of allowed string values
     * @param string|null   $description Optional description/help text
     * @param string[]|null $default     Optional default selected values (must be subset of enum)
     * @param int|null      $minItems    Optional minimum number of selections
     * @param int|null      $maxItems    Optional maximum number of selections
     */
    public function __construct(
        string $title,
        public readonly array $enum,
        ?string $description = null,
        public readonly ?array $default = null,
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
    ) {
        parent::__construct($title, $description);

        if ([] === $enum) {
            throw new InvalidArgumentException('enum array must not be empty.');
        }

        foreach ($enum as $value) {
            if (!\is_string($value)) {
                throw new InvalidArgumentException('All enum values must be strings.');
            }
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
                if (!\in_array($value, $enum, true)) {
                    throw new InvalidArgumentException(\sprintf('Default value "%s" is not in the enum array.', $value));
                }
            }
        }
    }

    /**
     * @param array{
     *     title: string,
     *     items: array{type: string, enum: string[]},
     *     description?: string,
     *     default?: string[],
     *     minItems?: int,
     *     maxItems?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::validateTitle($data, 'multi-select enum');

        if (!isset($data['items']['enum']) || !\is_array($data['items']['enum'])) {
            throw new InvalidArgumentException('Missing or invalid "items.enum" for multi-select enum schema definition.');
        }

        return new self(
            title: $data['title'],
            enum: $data['items']['enum'],
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
            'type' => 'string',
            'enum' => $this->enum,
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
