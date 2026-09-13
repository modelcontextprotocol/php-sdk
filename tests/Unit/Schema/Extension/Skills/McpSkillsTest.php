<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension\Skills;

use Mcp\Schema\Extension\Skills\GetSkillRequest;
use Mcp\Schema\Extension\Skills\ListSkillsRequest;
use Mcp\Schema\Extension\Skills\McpSkills;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\Skills\GetSkillHandler;
use Mcp\Server\Handler\Request\Skills\ListSkillsHandler;
use Mcp\Server\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class McpSkillsTest extends TestCase
{
    public function testExtensionIdentifierAndCapabilities(): void
    {
        $extension = new McpSkills(new SkillRegistry());

        $this->assertSame('io.modelcontextprotocol/skills', (string) $extension->getId());
        $this->assertSame([], $extension->getCapabilities());
    }

    public function testCapabilitiesSerializeAsEmptyObject(): void
    {
        $capabilities = new ServerCapabilities(extensions: [McpSkills::EXTENSION_ID => (new McpSkills(new SkillRegistry()))->getCapabilities()]);

        $json = json_encode($capabilities, \JSON_UNESCAPED_SLASHES);

        // The empty extension payload MUST serialize to `{}`, not `[]`.
        $this->assertStringContainsString('"io.modelcontextprotocol/skills":{}', $json);
        $this->assertStringNotContainsString('"io.modelcontextprotocol/skills":[]', $json);
    }

    public function testDeclaresListAndGetMessages(): void
    {
        $extension = new McpSkills(new SkillRegistry());

        $this->assertSame([ListSkillsRequest::class, GetSkillRequest::class], $extension->getMessages());
    }

    public function testServesListAndGetHandlers(): void
    {
        $extension = new McpSkills(new SkillRegistry());

        $handlers = iterator_to_array($extension->getRequestHandlers());

        $this->assertCount(2, $handlers);
        $this->assertInstanceOf(ListSkillsHandler::class, $handlers[0]);
        $this->assertInstanceOf(GetSkillHandler::class, $handlers[1]);
    }
}
