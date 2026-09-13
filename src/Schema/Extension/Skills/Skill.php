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
 * The entry for a single skill, returned identically by both `skills/list` and
 * `skills/get`: a complete manifest a host can verify and bind approval to,
 * without needing to fetch the skill's files first.
 *
 * @phpstan-import-type SkillMetadataData from SkillMetadata
 * @phpstan-import-type SkillResourceData from SkillResource
 *
 * @phpstan-type SkillData array{
 *     uri: string,
 *     frontmatter: SkillMetadataData,
 *     resources: SkillResourceData[]|'dynamic',
 * }
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Skill implements \JsonSerializable
{
    /**
     * @param string                    $uri         resource URI of the skill's SKILL.md, readable via resources/read
     * @param SkillMetadata             $frontmatter the skill's SKILL.md YAML frontmatter, rendered verbatim
     * @param SkillResource[]|'dynamic' $resources   a complete enumeration of SKILL.md and every supporting
     *                                               file, or "dynamic" when stable digests cannot be published
     */
    public function __construct(
        public readonly string $uri,
        public readonly SkillMetadata $frontmatter,
        public readonly array|string $resources,
    ) {
        if ('dynamic' !== $this->resources && !array_is_list($this->resources)) {
            throw new InvalidArgumentException('A skill\'s "resources" must be a list of SkillResource or the string "dynamic".');
        }
    }

    /**
     * @param SkillData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" in skill entry.');
        }
        if (!isset($data['frontmatter']) || !\is_array($data['frontmatter'])) {
            throw new InvalidArgumentException('Invalid or missing "frontmatter" in skill entry.');
        }

        $resources = $data['resources'] ?? null;
        if ('dynamic' === $resources) {
            $parsedResources = 'dynamic';
        } elseif (\is_array($resources)) {
            $parsedResources = array_map(SkillResource::fromArray(...), $resources);
        } else {
            throw new InvalidArgumentException('A skill entry\'s "resources" must be an array or the string "dynamic".');
        }

        return new self(
            uri: $data['uri'],
            frontmatter: SkillMetadata::fromArray($data['frontmatter']),
            resources: $parsedResources,
        );
    }

    /**
     * @return array{uri: string, frontmatter: SkillMetadata, resources: array<SkillResource>|'dynamic'}
     */
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri,
            'frontmatter' => $this->frontmatter,
            'resources' => 'dynamic' === $this->resources ? 'dynamic' : $this->resources,
        ];
    }
}
