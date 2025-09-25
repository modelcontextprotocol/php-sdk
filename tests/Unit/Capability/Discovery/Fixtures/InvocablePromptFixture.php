<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\McpPrompt;

#[McpPrompt(name: 'InvokableGreeterPrompt')]
class InvocablePromptFixture
{
    public function __invoke(string $personName): array
    {
        return [['role' => 'user', 'content' => "Generate a short greeting for {$personName}."]];
    }
}
