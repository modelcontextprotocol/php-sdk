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
     */
    public function __construct(
        string $title,
        public readonly array $anyOf,
        ?string $description = null,
    ) {
        parent::__construct($title, $description);

        if ([] === $anyOf) {
            throw new InvalidArgumentException('anyOf array must not be empty.');
        }

        foreach ($anyOf as $item) {
            if (!isset($item['const']) || !\is_string($item['const'])) {
                throw new InvalidArgumentException('Each anyOf item must have a string "const" property.');
            }
            if (!isset($item['title']) || !\is_string($item['title'])) {
                throw new InvalidArgumentException('Each anyOf item must have a string "title" property.');
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
                'anyOf' => $this->anyOf,
            ],
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return $data;
    }
}
