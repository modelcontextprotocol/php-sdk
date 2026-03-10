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
 * Schema definition for single-select enum fields with titled options (SEP-1330).
 *
 * Uses the oneOf pattern with const/title pairs instead of enum/enumNames.
 * Produces: {"type": "string", "oneOf": [{"const": "value", "title": "Label"}, ...]}
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1330
 */
final class TitledEnumSchemaDefinition extends AbstractSchemaDefinition
{
    /**
     * @param string                                    $title       Human-readable title for the field
     * @param list<array{const: string, title: string}> $oneOf       Array of const/title pairs
     * @param string|null                               $description Optional description/help text
     * @param string|null                               $default     Optional default value (must match a const)
     */
    public function __construct(
        string $title,
        public readonly array $oneOf,
        ?string $description = null,
        public readonly ?string $default = null,
    ) {
        parent::__construct($title, $description);

        if ([] === $oneOf) {
            throw new InvalidArgumentException('oneOf array must not be empty.');
        }

        $consts = [];
        foreach ($oneOf as $item) {
            if (!isset($item['const']) || !\is_string($item['const'])) {
                throw new InvalidArgumentException('Each oneOf item must have a string "const" property.');
            }
            if (!isset($item['title']) || !\is_string($item['title'])) {
                throw new InvalidArgumentException('Each oneOf item must have a string "title" property.');
            }
            $consts[] = $item['const'];
        }

        if (null !== $default && !\in_array($default, $consts, true)) {
            throw new InvalidArgumentException(\sprintf('Default value "%s" is not in the oneOf const values.', $default));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'string',
            'title' => $this->title,
            'oneOf' => $this->oneOf,
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        return $data;
    }
}
