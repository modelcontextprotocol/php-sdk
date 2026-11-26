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

use Mcp\Schema\Extension\Skills\Skill;

/**
 * The skills a server serves, keyed by the URI of each skill's SKILL.md.
 *
 * Populated by {@see SkillProvider} while it registers `skill://` resources,
 * and read by {@see \Mcp\Server\Handler\Request\Skills\ListSkillsHandler} and
 * {@see \Mcp\Server\Handler\Request\Skills\GetSkillHandler} to answer
 * `skills/list` and `skills/get`.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillRegistry
{
    /**
     * @var array<string, Skill>
     */
    private array $skills = [];

    /**
     * @var array<string, string> URI => real path of the file served there
     */
    private array $servedUris = [];

    public function add(Skill $skill): void
    {
        $this->skills[$skill->uri] = $skill;
    }

    /**
     * @return list<Skill> in registration order
     */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * Whether a file is already served at $uri, by this or another skill.
     *
     * A skill's manifest can list a file another skill serves (a nested skill's SKILL.md), so
     * this tracks what is actually registered as a resource, not what manifests list.
     *
     * @internal
     */
    public function isServed(string $uri): bool
    {
        return isset($this->servedUris[$uri]);
    }

    /**
     * The real path of the file served at $uri, to tell the same file reached through two skills
     * (a nested skill's supporting file) from two different files claiming one URI.
     *
     * @internal
     */
    public function servedPath(string $uri): ?string
    {
        return $this->servedUris[$uri] ?? null;
    }

    /**
     * @internal
     */
    public function markServed(string $uri, string $realPath): void
    {
        $this->servedUris[$uri] = $realPath;
    }

    public function get(string $uri): ?Skill
    {
        return $this->skills[$uri] ?? null;
    }
}
