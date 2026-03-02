<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    public function testAll()
    {
        $store = $this->getMockBuilder(InMemorySessionStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read'])
            ->getMock();
        $store->expects($this->once())->method('read')->willReturn(json_encode(['foo' => 'bar']));

        $session = new Session($store);
        $result = $session->all();
        $this->assertEquals(['foo' => 'bar'], $result);

        // Call again to make sure we dont read from Store
        $result = $session->all();
        $this->assertEquals(['foo' => 'bar'], $result);
    }

    public function testAllReturnsEmptyArrayForNullPayload()
    {
        $store = $this->getMockBuilder(InMemorySessionStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read'])
            ->getMock();
        $store->expects($this->once())->method('read')->willReturn('null');

        $session = new Session($store);
        $result = $session->all();

        $this->assertSame([], $result);
    }
}
