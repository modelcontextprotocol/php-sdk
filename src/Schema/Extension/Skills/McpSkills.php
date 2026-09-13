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

use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Server\Handler\Request\Skills\GetSkillHandler;
use Mcp\Server\Handler\Request\Skills\ListSkillsHandler;
use Mcp\Server\Skill\SkillRegistry;

/**
 * The MCP Skills extension (io.modelcontextprotocol/skills).
 *
 * Skills are multi-step workflow instructions ("how to orchestrate tools") that a server ships
 * alongside its tools. Per SEP-2640 each skill file is served through the existing Resources
 * primitive: `skill://<skill-path>/SKILL.md` (plus supporting files). The extension adds two
 * mandatory RPC methods — `skills/list` and `skills/get` — that return a complete, digest-and-size
 * manifest of a skill's files, so a host can build its registry, present a skill for approval, and
 * verify every later read without first fetching the files.
 *
 * Enable on the server either via {@see \Mcp\Server\Builder::addSkillsFromDirectory()}, which
 * builds and owns the {@see SkillRegistry} for you, or by constructing a `SkillRegistry` yourself,
 * registering it with {@see \Mcp\Server\Builder::enableExtension()}, and feeding it via
 * {@see \Mcp\Server\Skill\SkillProvider::registerInto()} for full control. Pick one: calling
 * `addSkillsFromDirectory()` after the extension is already enabled throws, since it always tries
 * to enable its own instance.
 *
 * `resources/directory/read`, gated behind the `directoryRead` capability setting, is not yet
 * implemented and is not declared.
 *
 * @see https://github.com/modelcontextprotocol/ext-skills
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class McpSkills implements ExtensionInterface
{
    public const EXTENSION_ID = 'io.modelcontextprotocol/skills';
    public const MIME_TYPE = 'text/markdown';
    public const URI_SCHEME = 'skill';
    public const ENTRY_POINT = 'SKILL.md';

    /**
     * The `_meta` namespace prefix reserved by this extension under which extra SKILL.md
     * frontmatter fields MAY be exposed on a skill resource descriptor.
     */
    public const META_PREFIX = 'io.modelcontextprotocol.skills/';

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(self::EXTENSION_ID);
    }

    /**
     * The Skills extension advertises an empty capability payload (`{}`): `directoryRead`
     * is not yet implemented.
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [ListSkillsRequest::class, GetSkillRequest::class];
    }

    public function getRequestHandlers(): iterable
    {
        yield new ListSkillsHandler($this->registry, $this->pageSize);
        yield new GetSkillHandler($this->registry);
    }
}
