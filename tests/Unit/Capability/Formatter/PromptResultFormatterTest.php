<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Formatter;

use Mcp\Capability\Formatter\PromptResultFormatter;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

class PromptResultFormatterTest extends TestCase
{
    public function testFormatPromptMessage(): void
    {
        $message = new PromptMessage(Role::User, new TextContent('hello'));
        $result = (new PromptResultFormatter())->format($message);
        $this->assertCount(1, $result);
        $this->assertSame($message, $result[0]);
    }

    public function testFormatUserAssistantShorthand(): void
    {
        $result = (new PromptResultFormatter())->format([
            'user' => 'Hello',
            'assistant' => 'Hi there',
        ]);
        $this->assertCount(2, $result);
        $this->assertSame(Role::User, $result[0]->role);
        $this->assertSame(Role::Assistant, $result[1]->role);
    }

    public function testFormatRoleContentArray(): void
    {
        $result = (new PromptResultFormatter())->format([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame(Role::User, $result[0]->role);
    }
}
