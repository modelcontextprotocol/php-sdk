<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Skills;

use Mcp\Exception\InvalidArgumentException;

/**
 * The parsed YAML frontmatter of a `SKILL.md` file, rendered verbatim per the
 * `Skill.frontmatter` shape: `name` and `description` are always present,
 * every other field the author wrote passes through unchanged in {@see self::$extra}.
 *
 * @phpstan-type SkillMetadataData array{name: string, description: string, ...}
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillMetadata implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $extra additional frontmatter fields (everything but name/description)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data the raw frontmatter mapping
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('SKILL.md frontmatter must contain a non-empty string "name".');
        }

        if (empty($data['description']) || !\is_string($data['description'])) {
            throw new InvalidArgumentException('SKILL.md frontmatter must contain a non-empty string "description".');
        }

        $extra = $data;
        unset($extra['name'], $extra['description']);

        return new self(
            name: $data['name'],
            description: $data['description'],
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            ...$this->extra,
        ];
    }
}
