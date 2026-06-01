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

/**
 * The Agent Skills discovery index, served as the well-known `skill://index.json` resource.
 *
 * @phpstan-import-type SkillDiscoveryEntryData from SkillDiscoveryEntry
 *
 * @phpstan-type SkillDiscoveryIndexData array{'$schema': string, skills: SkillDiscoveryEntryData[]}
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillDiscoveryIndex implements \JsonSerializable
{
    public const SCHEMA_URL = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    /**
     * @param SkillDiscoveryEntry[] $skills
     */
    public function __construct(
        public readonly array $skills,
        public readonly string $schema = self::SCHEMA_URL,
    ) {
    }

    /**
     * @param SkillDiscoveryIndexData $data
     */
    public static function fromArray(array $data): self
    {
        $skills = [];
        foreach ($data['skills'] ?? [] as $entry) {
            $skills[] = SkillDiscoveryEntry::fromArray($entry);
        }

        return new self(
            skills: $skills,
            schema: isset($data['$schema']) && \is_string($data['$schema']) ? $data['$schema'] : self::SCHEMA_URL,
        );
    }

    /**
     * @return array{'$schema': string, skills: SkillDiscoveryEntry[]}
     */
    public function jsonSerialize(): array
    {
        return [
            '$schema' => $this->schema,
            'skills' => $this->skills,
        ];
    }
}
