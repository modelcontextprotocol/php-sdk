<?php

declare(strict_types=1);

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
 * Schema definition for number/integer fields in elicitation requests.
 *
 * Supports minimum and maximum value constraints.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class NumberSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string         $title       Human-readable title for the field
     * @param bool           $integerOnly Whether to restrict to integer values only
     * @param string|null    $description Optional description/help text
     * @param int|float|null $default     Optional default value
     * @param int|float|null $minimum     Optional minimum value (inclusive)
     * @param int|float|null $maximum     Optional maximum value (inclusive)
     */
    public function __construct(
        string $title,
        public readonly bool $integerOnly = false,
        ?string $description = null,
        public readonly int|float|null $default = null,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
    ) {
        parent::__construct($title, $description);

        if (null !== $minimum && null !== $maximum && $minimum > $maximum) {
            throw new InvalidArgumentException('minimum cannot be greater than maximum.');
        }

        if (null !== $default && null !== $minimum && $default < $minimum) {
            throw new InvalidArgumentException('default value cannot be less than minimum.');
        }

        if (null !== $default && null !== $maximum && $default > $maximum) {
            throw new InvalidArgumentException('default value cannot be greater than maximum.');
        }

        if ($integerOnly && null !== $default && $default !== (int) $default) {
            throw new InvalidArgumentException('default value must be an integer when integerOnly is true.');
        }
    }

    /**
     * @param array{
     *     type: string,
     *     title: string,
     *     description?: string,
     *     default?: int|float,
     *     minimum?: int|float,
     *     maximum?: int|float,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::validateTitle($data, 'number');

        $type = $data['type'] ?? 'number';
        $integerOnly = 'integer' === $type;

        return new self(
            title: $data['title'],
            integerOnly: $integerOnly,
            description: $data['description'] ?? null,
            default: $data['default'] ?? null,
            minimum: $data['minimum'] ?? null,
            maximum: $data['maximum'] ?? null,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     description?: string,
     *     default?: int|float,
     *     minimum?: int|float,
     *     maximum?: int|float,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = $this->buildBaseJson($this->integerOnly ? 'integer' : 'number');

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        if (null !== $this->minimum) {
            $data['minimum'] = $this->minimum;
        }

        if (null !== $this->maximum) {
            $data['maximum'] = $this->maximum;
        }

        return $data;
    }
}
