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
 * Schema definition for string enum fields in elicitation requests.
 *
 * Provides a list of allowed values with optional human-readable labels.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EnumSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string        $title       Human-readable title for the field
     * @param string[]      $enum        Array of allowed string values
     * @param string|null   $description Optional description/help text
     * @param string|null   $default     Optional default value (must be in enum)
     * @param string[]|null $enumNames   Optional human-readable labels for each enum value
     */
    public function __construct(
        string $title,
        public readonly array $enum,
        ?string $description = null,
        public readonly ?string $default = null,
        public readonly ?array $enumNames = null,
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

        if (null !== $enumNames && \count($enumNames) !== \count($enum)) {
            throw new InvalidArgumentException('enumNames length must match enum length.');
        }

        if (null !== $default && !\in_array($default, $enum, true)) {
            throw new InvalidArgumentException(\sprintf('Default value "%s" is not in the enum array.', $default));
        }
    }

    /**
     * @param array{
     *     title: string,
     *     enum: string[],
     *     description?: string,
     *     default?: string,
     *     enumNames?: string[],
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::validateTitle($data, 'enum');

        if (!isset($data['enum']) || !\is_array($data['enum'])) {
            throw new InvalidArgumentException('Missing or invalid "enum" for enum schema definition.');
        }

        return new self(
            title: $data['title'],
            enum: $data['enum'],
            description: $data['description'] ?? null,
            default: $data['default'] ?? null,
            enumNames: $data['enumNames'] ?? null,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     enum: string[],
     *     description?: string,
     *     default?: string,
     *     enumNames?: string[],
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'string',
            'title' => $this->title,
            'enum' => $this->enum,
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        if (null !== $this->enumNames) {
            $data['enumNames'] = $this->enumNames;
        }

        return $data;
    }
}
