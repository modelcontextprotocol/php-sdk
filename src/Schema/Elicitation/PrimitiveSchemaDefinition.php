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
 * Factory class for creating primitive schema definitions from array data.
 *
 * Dispatches to the correct schema definition class based on the "type" field.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class PrimitiveSchemaDefinition
{
    /**
     * Create a schema definition from array data.
     *
     * @param array{
     *     type: string,
     *     title: string,
     *     description?: string,
     *     default?: mixed,
     *     enum?: string[],
     *     enumNames?: string[],
     *     format?: string,
     *     minLength?: int,
     *     maxLength?: int,
     *     minimum?: int|float,
     *     maximum?: int|float,
     * } $data
     */
    public static function fromArray(array $data): StringSchemaDefinition|NumberSchemaDefinition|BooleanSchemaDefinition|EnumSchemaDefinition
    {
        if (!isset($data['type']) || !\is_string($data['type'])) {
            throw new InvalidArgumentException('Missing or invalid "type" for primitive schema definition.');
        }

        return match ($data['type']) {
            'string' => isset($data['enum']) ? EnumSchemaDefinition::fromArray($data) : StringSchemaDefinition::fromArray($data),
            'integer', 'number' => NumberSchemaDefinition::fromArray($data),
            'boolean' => BooleanSchemaDefinition::fromArray($data),
            default => throw new InvalidArgumentException(\sprintf('Unsupported primitive type "%s". Supported types are: string, integer, number, boolean.', $data['type'])),
        };
    }
}
