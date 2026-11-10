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
use Symfony\Component\Yaml\Exception\ParseException;
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
        $split = $this->split($content);
        if (null === $split) {
            return [[], $content];
        }

        [$yaml, $body] = $split;

        if (!class_exists(Yaml::class)) {
            throw new RuntimeException('Parsing SKILL.md frontmatter requires the "symfony/yaml" component. Run: composer require symfony/yaml');
        }

        // Nested mappings stay objects, so an empty `metadata: {}` is sent as `{}` and not as `[]`.
        // The spec requires the frontmatter in a skill entry to be identical in content to the file's.
        // PARSE_EXCEPTION_ON_INVALID_TYPE makes `!php/object` and friends fail, as they would otherwise
        // silently become null, which is not what the file says.
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE) ?? [];
        } catch (ParseException $e) {
            throw new InvalidArgumentException(\sprintf('SKILL.md frontmatter is not valid YAML: %s', $e->getMessage()), 0, $e);
        }
        if ($data instanceof \stdClass) {
            $data = get_object_vars($data);
        } elseif (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new InvalidArgumentException('SKILL.md frontmatter must be a YAML mapping.');
        }

        $this->rejectDates($data, '');

        // NAN, INF and binary strings have no JSON form. Found here, they name the SKILL.md, and
        // not later in a skills/list answer that fails for every skill on the page.
        try {
            json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(\sprintf('SKILL.md frontmatter cannot be sent as JSON: %s. Quote the value or remove it.', $e->getMessage()), 0, $e);
        }

        /* @var array<string, mixed> $data */
        return [$data, $body];
    }

    /**
     * Whether the document opens with a frontmatter block, i.e. is meant as a skill.
     */
    public function hasFrontmatter(string $content): bool
    {
        return null !== $this->split($content);
    }

    /**
     * Splits the leading `---` delimited block from the body. Line based and not a single regular
     * expression, so a large block cannot hit the PCRE backtrack limit.
     *
     * @return array{0: string, 1: string}|null the [yaml, body] pair, or null without a frontmatter block
     */
    private function split(string $content): ?array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (1 !== preg_match('/\A---\R/', $content, $opening)) {
            return null;
        }

        $offset = \strlen($opening[0]);
        if (1 !== preg_match('/^---(?:\R|\z)/m', $content, $closing, \PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }

        $yaml = substr($content, $offset, $closing[0][1] - $offset);

        return [preg_replace('/\R\z/', '', $yaml) ?? $yaml, substr($content, $closing[0][1] + \strlen($closing[0][0]))];
    }

    /**
     * YAML reads an unquoted `2026-01-01` as a date, which has no single JSON form: a host that
     * compares the frontmatter with the file would see a different value than the one sent.
     * Quoting the value keeps it a string.
     *
     * @throws InvalidArgumentException if a value is a YAML date
     */
    private function rejectDates(mixed $value, string $path): void
    {
        if ($value instanceof \DateTimeInterface) {
            throw new InvalidArgumentException(\sprintf('SKILL.md frontmatter field "%s" is a YAML date. Quote the value (e.g. "2026-01-01") so it stays a string.', $path));
        }

        if (\is_array($value) || $value instanceof \stdClass) {
            foreach ((array) $value as $key => $item) {
                $this->rejectDates($item, '' === $path ? (string) $key : $path.'.'.$key);
            }
        }
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
