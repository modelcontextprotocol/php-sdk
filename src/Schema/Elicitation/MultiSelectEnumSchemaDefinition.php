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
 * Schema definition for multi-select enum fields without titles (SEP-1330).
 *
 * Produces: {"type": "array", "items": {"type": "string", "enum": [...]}}
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1330
 */
final class MultiSelectEnumSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string      $title       Human-readable title for the field
     * @param string[]    $enum        Array of allowed string values
     * @param string|null $description Optional description/help text
     */
    public function __construct(
        string $title,
        public readonly array $enum,
        ?string $description = null,
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
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'array',
            'title' => $this->title,
            'items' => [
                'type' => 'string',
                'enum' => $this->enum,
            ],
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return $data;
    }
}
