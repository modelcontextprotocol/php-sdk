<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Tests\Fixtures\Enum\BackedStringEnum;

class DiscoverableToolHandler
{
    /**
     * A basic discoverable tool.
     *
     * @param string $name the name to greet
     *
     * @return string the greeting
     */
    #[McpTool(name: 'greet_user', description: 'Greets a user by name.')]
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }

    /**
     * A tool with more complex parameters and inferred name/description.
     *
     * @param int              $count  the number of times to repeat
     * @param bool             $loudly Should it be loud?
     * @param BackedStringEnum $mode   the mode of operation
     *
     * @return array an array with results
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function repeatAction(int $count, bool $loudly = false, BackedStringEnum $mode = BackedStringEnum::OptionA): array
    {
        return ['count' => $count, 'loudly' => $loudly, 'mode' => $mode->value, 'message' => 'Action repeated.'];
    }

    // This method should NOT be discovered as a tool
    public function internalHelperMethod(int $value): int
    {
        return $value * 2;
    }

    #[McpTool(name: 'protected_tool_should_be_ignored')] // On protected method
    protected function aProtectedTool(): void
    {
    }

    #[McpTool(name: 'static_tool_should_be_ignored')] // On static method
    public static function aStaticTool(): void
    {
    }
}
