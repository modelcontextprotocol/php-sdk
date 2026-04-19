<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry\ToolReference;
use PHPUnit\Framework\TestCase;

class DiscovererToolTitleTest extends TestCase
{
    public function testDiscoveryPropagatesMcpToolTitleToToolTitle(): void
    {
        $discoverer = new Discoverer();

        $discovery = $discoverer->discover(__DIR__, ['TitleFixture']);

        $tools = $discovery->getTools();

        $this->assertArrayHasKey('titled_tool', $tools);
        $toolRef = $tools['titled_tool'];
        $this->assertInstanceOf(ToolReference::class, $toolRef);
        $this->assertSame('Display Title', $toolRef->tool->title);
    }
}
