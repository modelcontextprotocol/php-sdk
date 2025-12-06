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

use Mcp\Capability\Formatter\ResourceResultFormatter;
use Mcp\Schema\Content\TextResourceContents;
use PHPUnit\Framework\TestCase;

class ResourceResultFormatterTest extends TestCase
{
    public function testFormatStringResult(): void
    {
        $result = (new ResourceResultFormatter())->format('content', 'file://test');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextResourceContents::class, $result[0]);
    }

    public function testFormatResourceContents(): void
    {
        $contents = new TextResourceContents('file://test', 'text/plain', 'content');
        $result = (new ResourceResultFormatter())->format($contents, 'file://test');
        $this->assertSame([$contents], $result);
    }

    public function testFormatWithMimeType(): void
    {
        $result = (new ResourceResultFormatter())->format('content', 'file://test', 'text/html');
        $this->assertCount(1, $result);
        $this->assertSame('text/html', $result[0]->mimeType);
    }
}
