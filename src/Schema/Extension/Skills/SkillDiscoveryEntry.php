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
 * A single entry in the Agent Skills discovery index served at `skill://index.json`.
 *
 * An entry mirrors the skill's `SKILL.md` frontmatter verbatim and points at the skill via a
 * direct `url` (the `SKILL.md` resource, with its content `digest`), one or more `archives`, or
 * both. Every entry MUST provide a `url`, a non-empty `archives`, or both; a `digest` is present
 * exactly when `url` is.
 *
 * @phpstan-import-type SkillArchiveData from SkillArchive
 *
 * @phpstan-type SkillDiscoveryEntryData array{
 *     frontmatter: array<string, mixed>,
 *     url?: string,
 *     digest?: string,
 *     archives?: SkillArchiveData[],
 * }
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillDiscoveryEntry implements \JsonSerializable
{
    /**
     * @param SkillArchive[] $archives
     */
    public function __construct(
        public readonly SkillMetadata $frontmatter,
        public readonly ?string $url = null,
        public readonly ?string $digest = null,
        public readonly array $archives = [],
    ) {
        if (null !== $this->url && null === $this->digest) {
            throw new InvalidArgumentException('A skill discovery entry with a "url" must also carry its "digest".');
        }
        if (null === $this->url && null !== $this->digest) {
            throw new InvalidArgumentException('A skill discovery entry "digest" is only valid alongside a "url".');
        }
        if (null === $this->url && [] === $this->archives) {
            throw new InvalidArgumentException('A skill discovery entry must provide a "url", a non-empty "archives", or both.');
        }
    }

    /**
     * @param SkillDiscoveryEntryData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['frontmatter']) || !\is_array($data['frontmatter'])) {
            throw new InvalidArgumentException('Invalid or missing "frontmatter" in skill discovery entry.');
        }

        $archives = [];
        foreach ($data['archives'] ?? [] as $archive) {
            $archives[] = SkillArchive::fromArray($archive);
        }

        return new self(
            frontmatter: SkillMetadata::fromArray($data['frontmatter']),
            url: isset($data['url']) && \is_string($data['url']) ? $data['url'] : null,
            digest: isset($data['digest']) && \is_string($data['digest']) ? $data['digest'] : null,
            archives: $archives,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [];
        if (null !== $this->url) {
            $data['url'] = $this->url;
            $data['digest'] = $this->digest;
        }
        $data['frontmatter'] = $this->frontmatter;
        if ([] !== $this->archives) {
            $data['archives'] = $this->archives;
        }

        return $data;
    }
}
