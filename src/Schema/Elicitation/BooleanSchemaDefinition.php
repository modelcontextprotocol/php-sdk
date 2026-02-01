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
 * Schema definition for boolean fields in elicitation requests.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class BooleanSchemaDefinition implements \JsonSerializable
{
    /**
     * @param string      $title       Human-readable title for the field
     * @param string|null $description Optional description/help text
     * @param bool|null   $default     Optional default value
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?bool $default = null,
    ) {
    }

    /**
     * @param array{
     *     title: string,
     *     description?: string,
     *     default?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['title']) || !\is_string($data['title'])) {
            throw new InvalidArgumentException('Missing or invalid "title" for boolean schema definition.');
        }

        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            default: isset($data['default']) ? (bool) $data['default'] : null,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     description?: string,
     *     default?: bool,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'boolean',
            'title' => $this->title,
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
