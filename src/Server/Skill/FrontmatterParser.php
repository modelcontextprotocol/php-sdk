<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Skill;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses the leading YAML frontmatter block of a `SKILL.md` file.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class FrontmatterParser
{
    /**
     * Splits a `SKILL.md` document into its frontmatter mapping and the remaining markdown body.
     *
     * A document without a leading `---` delimited block is treated as having empty frontmatter.
     *
     * @return array{0: array<string, mixed>, 1: string} the [frontmatter, body] pair
     *
     * @throws RuntimeException         if symfony/yaml is not installed
     * @throws InvalidArgumentException if the frontmatter is present but is not a YAML mapping
     */
    public function parse(string $content): array
    {
        if (!preg_match('/^(?:\xEF\xBB\xBF)?---\R(.*?)\R---\R?(.*)$/s', $content, $matches)) {
            return [[], $content];
        }

        if (!class_exists(Yaml::class)) {
            throw new RuntimeException('Parsing SKILL.md frontmatter requires the "symfony/yaml" component. Run: composer require symfony/yaml');
        }

        // Nested mappings stay objects, so an empty `metadata: {}` is sent as `{}` and not as `[]`.
        // The spec requires the frontmatter in a skill entry to be identical in content to the file's.
        $data = Yaml::parse($matches[1], Yaml::PARSE_OBJECT_FOR_MAP) ?? [];
        if ($data instanceof \stdClass) {
            $data = get_object_vars($data);
        } elseif (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new InvalidArgumentException('SKILL.md frontmatter must be a YAML mapping.');
        }

        /* @var array<string, mixed> $data */
        return [$data, $matches[2]];
    }

    /**
     * Parses the frontmatter of a `SKILL.md` document into a {@see SkillMetadata} value object.
     */
    public function parseMetadata(string $content): SkillMetadata
    {
        [$frontmatter] = $this->parse($content);

        return SkillMetadata::fromArray($frontmatter);
    }
}
