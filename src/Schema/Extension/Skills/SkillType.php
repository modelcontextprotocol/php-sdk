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
 * The type of a skill entry in the Agent Skills discovery index.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
enum SkillType: string
{
    /**
     * A concrete skill backed by a `SKILL.md` resource.
     */
    case SkillMd = 'skill-md';

    /**
     * A parameterized skill namespace backed by an MCP resource template.
     */
    case McpResourceTemplate = 'mcp-resource-template';
}
