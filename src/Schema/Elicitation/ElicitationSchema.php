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
 * Schema wrapper for elicitation requestedSchema (JSON Schema object type).
 *
 * Represents an object schema with primitive property definitions and optional required fields.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ElicitationSchema implements \JsonSerializable
{
    /**
     * @param array<string, StringSchemaDefinition|NumberSchemaDefinition|BooleanSchemaDefinition|EnumSchemaDefinition> $properties Property definitions keyed by name
     * @param string[]                                                                                                  $required   Array of required property names
     */
    public function __construct(
        public readonly array $properties,
        public readonly array $required = [],
    ) {
        if ([] === $properties) {
            throw new InvalidArgumentException('properties array must not be empty.');
        }

        foreach ($required as $name) {
            if (!\array_key_exists($name, $properties)) {
                throw new InvalidArgumentException(\sprintf('Required property "%s" is not defined in properties.', $name));
            }
        }
    }

    /**
     * Create an ElicitationSchema from array data.
     *
     * @param array{
     *     type?: string,
     *     properties: array<string, array{type: string, title: string, ...}>,
     *     required?: string[],
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['type']) && 'object' !== $data['type']) {
            throw new InvalidArgumentException('ElicitationSchema type must be "object".');
        }

        if (!isset($data['properties']) || !\is_array($data['properties'])) {
            throw new InvalidArgumentException('Missing or invalid "properties" for elicitation schema.');
        }

        $properties = [];
        foreach ($data['properties'] as $name => $propertyData) {
            if (!\is_array($propertyData)) {
                throw new InvalidArgumentException(\sprintf('Property "%s" must be an array.', $name));
            }
            $properties[$name] = PrimitiveSchemaDefinition::fromArray($propertyData);
        }

        return new self(
            properties: $properties,
            required: $data['required'] ?? [],
        );
    }

    /**
     * @return array{
     *     type: string,
     *     properties: array<string, mixed>,
     *     required?: string[],
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($this->properties as $name => $property) {
            $data['properties'][$name] = $property->jsonSerialize();
        }

        if ([] !== $this->required) {
            $data['required'] = $this->required;
        }

        return $data;
    }
}
