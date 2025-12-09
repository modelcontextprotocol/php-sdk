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

use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\TestCase;

class ToolResultFormatterTest extends TestCase
{
    public function testFormatStringResult(): void
    {
        $result = (new ToolResultFormatter())->format('hello');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextContent::class, $result[0]);
        $this->assertSame('hello', $result[0]->text);
    }

    public function testFormatContentResult(): void
    {
        $content = new TextContent('test');
        $result = (new ToolResultFormatter())->format($content);
        $this->assertSame([$content], $result);
    }

    public function testFormatArrayResult(): void
    {
        $result = (new ToolResultFormatter())->format(['key' => 'value']);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextContent::class, $result[0]);
        $this->assertStringContainsString('value', $result[0]->text);
    }

    public function testFormatNullResult(): void
    {
        $result = (new ToolResultFormatter())->format(null);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextContent::class, $result[0]);
        $this->assertSame('(null)', $result[0]->text);
    }

    public function testFormatBoolResult(): void
    {
        $result = (new ToolResultFormatter())->format(true);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextContent::class, $result[0]);
        $this->assertSame('true', $result[0]->text);
    }
}
