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
 * Base class for schema definitions in elicitation requests.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
abstract class AbstractSchemaDefinition implements \JsonSerializable
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * Validate that title exists and is a string in the data array.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    protected static function validateTitle(array $data, string $schemaType): void
    {
        if (!isset($data['title']) || !\is_string($data['title'])) {
            throw new InvalidArgumentException(\sprintf('Missing or invalid "title" for %s schema definition.', $schemaType));
        }
    }

    /**
     * Build the base JSON structure with type, title, and optional description.
     *
     * @return array<string, mixed>
     */
    protected function buildBaseJson(string $type): array
    {
        $data = [
            'type' => $type,
            'title' => $this->title,
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function jsonSerialize(): array;
}
