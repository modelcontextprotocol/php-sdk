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

    public function get(string $uri): ?Skill
    {
        return $this->skills[$uri] ?? null;
    }
}
