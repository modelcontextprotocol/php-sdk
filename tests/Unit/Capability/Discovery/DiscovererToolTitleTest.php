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

        $discovery = $discoverer->discover(__DIR__, ['Fixtures']);

        $tools = $discovery->getTools();

        $this->assertArrayHasKey('greet_user', $tools);
        $toolRef = $tools['greet_user'];
        $this->assertInstanceOf(ToolReference::class, $toolRef);
        $this->assertSame('Greet User', $toolRef->tool->title);
    }
}
