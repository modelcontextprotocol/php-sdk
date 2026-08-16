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
 * The parsed YAML frontmatter of a `SKILL.md` file.
 *
 * The `name` and `description` map to the Resource fields of the same name; any remaining
 * frontmatter keys are preserved in {@see self::$extra} and MAY be exposed under the
 * {@see McpSkills::META_PREFIX} `_meta` namespace.
 *
 * @phpstan-type SkillMetadataData array{name: string, description?: string, ...}
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
        public readonly ?string $description = null,
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

        $description = isset($data['description']) && \is_string($data['description']) ? $data['description'] : null;

        $extra = $data;
        unset($extra['name'], $extra['description']);

        return new self(
            name: $data['name'],
            description: $description,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return [...$data, ...$this->extra];
    }
}
