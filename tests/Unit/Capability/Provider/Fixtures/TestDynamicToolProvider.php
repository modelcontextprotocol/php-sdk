<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Provider\Fixtures;

use Mcp\Capability\Provider\DynamicToolProviderInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Tool;

/**
 * Test fixture for DynamicToolProviderInterface.
 *
 * This class provides a simple implementation for testing dynamic tool providers.
 */
final class TestDynamicToolProvider implements DynamicToolProviderInterface
{
    /**
     * @param array<Tool> $tools
     */
    public function __construct(
        private readonly array $tools = [],
    ) {
    }

    public function getTools(): iterable
    {
        return $this->tools;
    }

    public function supportsTool(string $toolName): bool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $toolName) {
                return true;
            }
        }

        return false;
    }

    public function executeTool(string $toolName, array $arguments): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $toolName) {
                return new TextContent("Executed {$toolName} with arguments: ".json_encode($arguments));
            }
        }

        throw new \RuntimeException("Tool {$toolName} not found");
    }
}
