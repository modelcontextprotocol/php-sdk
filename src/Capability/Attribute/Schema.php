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

namespace Mcp\Capability\Attribute;

use Attribute;

/**
 * Defines a JSON Schema for a method's input or an individual parameter.
 *
 * When used at the method level, it describes an object schema where properties
 * correspond to the method's parameters.
 *
 * When used at the parameter level, it describes the schema for that specific parameter.
 * If 'type' is omitted at the parameter level, it will be inferred.
 *
 * @phpstan-type SchemaAttributeData array{
 *     definition?: array<string, mixed>,
 *     type?: string,
 *     description?: string,
 *     enum?: array<int, float|string|int>,
 *     gormat?: string,
 *     minLength?: int,
 *     maxLength?: int,
 *     pattern?: string,
 *     minimum?: int,
 *     maximum?: int,
 *     exclusiveMinimum?: int,
 *     exclusiveMaximum?: int,
 *     multipleOf?: int|float,
 *     items?: array<string, mixed>,
 *     minItems?: int,
 *     maxItems?: int,
 *     uniqueItems?: bool,
 *     properties?: array<string, mixed>,
 *     required?: array<int, string>,
 *     additionalProperties?: bool|array<string, mixed>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Schema
{
    /**
     * The complete JSON schema array.
     * If provided, it takes precedence over individual properties like $type, $properties, etc.
     *
     * @var ?array<string, mixed>
     */
    public ?array $definition = null;

    /**
     * Alternatively, provide individual top-level schema keywords.
     * These are used if $definition is null.
     */
    public ?string $type = null;
    public ?string $description = null;
    public mixed $default = null;
    /**
     * @var ?array<int, float|string|int>
     */
    public ?array $enum = null; // list of allowed values
    public ?string $format = null; // e.g., 'email', 'date-time'

    // Constraints for string
    public ?int $minLength = null;
    public ?int $maxLength = null;
    public ?string $pattern = null;

    // Constraints for number/integer
    public int|float|null $minimum = null;
    public int|float|null $maximum = null;
    public ?bool $exclusiveMinimum = null;
    public ?bool $exclusiveMaximum = null;
    public int|float|null $multipleOf = null;

    // Constraints for array
    /**
     * @var ?array<string, mixed>
     */
    public ?array $items = null; // JSON schema for array items
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public ?bool $uniqueItems = null;

    // Constraints for object (primarily used when Schema is on a method or an object-typed parameter)
    /**
     * @var ?array<string, mixed>
     */
    public ?array $properties = null; // [propertyName => [schema array], ...]
    /**
     * @var ?array<int, string>
     */
    public ?array $required = null;   // [propertyName, ...]
    /**
     * @var bool|array<string, mixed>|null
     */
    public bool|array|null $additionalProperties = null; // true, false, or a schema array

    /**
     * @param ?array<string, mixed>          $definition           A complete JSON schema array. If provided, other parameters are ignored.
     * @param ?string                        $type                 the JSON schema type
     * @param ?string                        $description          description of the element
     * @param ?array<int, float|string|int>  $enum                 allowed enum values
     * @param ?string                        $format               String format (e.g., 'date-time', 'email').
     * @param ?int                           $minLength            minimum length for strings
     * @param ?int                           $maxLength            maximum length for strings
     * @param ?string                        $pattern              regex pattern for strings
     * @param int|float|null                 $minimum              minimum value for numbers/integers
     * @param int|float|null                 $maximum              maximum value for numbers/integers
     * @param ?bool                          $exclusiveMinimum     exclusive minimum
     * @param ?bool                          $exclusiveMaximum     exclusive maximum
     * @param int|float|null                 $multipleOf           must be a multiple of this value
     * @param ?array<string, mixed>          $items                JSON Schema for items if type is 'array'
     * @param ?int                           $minItems             minimum items for an array
     * @param ?int                           $maxItems             maximum items for an array
     * @param ?bool                          $uniqueItems          whether array items must be unique
     * @param ?array<string, mixed>          $properties           Property definitions if type is 'object'. [name => schema_array].
     * @param ?array<int, string>            $required             list of required properties for an object
     * @param bool|array<string, mixed>|null $additionalProperties policy for additional properties in an object
     */
    public function __construct(
        ?array $definition = null,
        ?string $type = null,
        ?string $description = null,
        ?array $enum = null,
        ?string $format = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?bool $exclusiveMinimum = null,
        ?bool $exclusiveMaximum = null,
        int|float|null $multipleOf = null,
        ?array $items = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        ?array $properties = null,
        ?array $required = null,
        bool|array|null $additionalProperties = null,
    ) {
        if (null !== $definition) {
            $this->definition = $definition;
        } else {
            $this->type = $type;
            $this->description = $description;
            $this->enum = $enum;
            $this->format = $format;
            $this->minLength = $minLength;
            $this->maxLength = $maxLength;
            $this->pattern = $pattern;
            $this->minimum = $minimum;
            $this->maximum = $maximum;
            $this->exclusiveMinimum = $exclusiveMinimum;
            $this->exclusiveMaximum = $exclusiveMaximum;
            $this->multipleOf = $multipleOf;
            $this->items = $items;
            $this->minItems = $minItems;
            $this->maxItems = $maxItems;
            $this->uniqueItems = $uniqueItems;
            $this->properties = $properties;
            $this->required = $required;
            $this->additionalProperties = $additionalProperties;
        }
    }

    /**
     * Converts the attribute's definition to a JSON schema array.
     *
     * @return SchemaAttributeData
     */
    public function toArray(): array
    {
        if (null !== $this->definition) {
            return [
                'definition' => $this->definition,
            ];
        }

        $schema = [];
        if (null !== $this->type) {
            $schema['type'] = $this->type;
        }
        if (null !== $this->description) {
            $schema['description'] = $this->description;
        }
        if (null !== $this->enum) {
            $schema['enum'] = $this->enum;
        }
        if (null !== $this->format) {
            $schema['format'] = $this->format;
        }

        // String
        if (null !== $this->minLength) {
            $schema['minLength'] = $this->minLength;
        }
        if (null !== $this->maxLength) {
            $schema['maxLength'] = $this->maxLength;
        }
        if (null !== $this->pattern) {
            $schema['pattern'] = $this->pattern;
        }

        // Numeric
        if (null !== $this->minimum) {
            $schema['minimum'] = $this->minimum;
        }
        if (null !== $this->maximum) {
            $schema['maximum'] = $this->maximum;
        }
        if (null !== $this->exclusiveMinimum) {
            $schema['exclusiveMinimum'] = $this->exclusiveMinimum;
        }
        if (null !== $this->exclusiveMaximum) {
            $schema['exclusiveMaximum'] = $this->exclusiveMaximum;
        }
        if (null !== $this->multipleOf) {
            $schema['multipleOf'] = $this->multipleOf;
        }

        // Array
        if (null !== $this->items) {
            $schema['items'] = $this->items;
        }
        if (null !== $this->minItems) {
            $schema['minItems'] = $this->minItems;
        }
        if (null !== $this->maxItems) {
            $schema['maxItems'] = $this->maxItems;
        }
        if (null !== $this->uniqueItems) {
            $schema['uniqueItems'] = $this->uniqueItems;
        }

        // Object
        if (null !== $this->properties) {
            $schema['properties'] = $this->properties;
        }
        if (null !== $this->required) {
            $schema['required'] = $this->required;
        }
        if (null !== $this->additionalProperties) {
            $schema['additionalProperties'] = $this->additionalProperties;
        }

        return $schema;
    }
}
