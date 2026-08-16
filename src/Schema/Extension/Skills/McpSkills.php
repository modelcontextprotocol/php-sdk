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

use Mcp\Schema\Extension\ServerExtensionInterface;

/**
 * The MCP Skills extension (io.modelcontextprotocol/skills).
 *
 * Skills are multi-step workflow instructions ("how to orchestrate tools") that a server ships
 * alongside its tools. Per SEP-2640 they are served through the existing Resources primitive with
 * zero protocol changes: each skill is exposed as a `skill://<skill-path>/SKILL.md` resource (plus
 * any supporting files), and the server advertises this extension during capability negotiation.
 *
 * Enable on the server via {@see \Mcp\Server\Builder::enableExtension()}, or use the
 * {@see \Mcp\Server\Builder::addSkillsFromDirectory()} convenience to expose a directory of skills.
 *
 * @see https://github.com/modelcontextprotocol/experimental-ext-skills
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class McpSkills implements ServerExtensionInterface
{
    public const EXTENSION_ID = 'io.modelcontextprotocol/skills';
    public const MIME_TYPE = 'text/markdown';
    public const URI_SCHEME = 'skill';
    public const ENTRY_POINT = 'SKILL.md';
    public const DISCOVERY_URI = 'skill://index.json';

    /**
     * The (not-yet-standardized) `_meta` namespace prefix under which extra SKILL.md frontmatter
     * fields are exposed on a skill resource descriptor.
     */
    public const META_PREFIX = 'io.modelcontextprotocol.skills/';

    public function getId(): string
    {
        return self::EXTENSION_ID;
    }

    /**
     * The Skills extension advertises an empty capability payload (`{}`).
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return [];
    }
}
