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

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\Psr6SessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Uid\Uuid;

final class Psr6SessionStoreTest extends TestCase
{
    public function testExistsReportsAMissingSession(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('hasItem')->willReturn(false);

        $this->assertFalse((new Psr6SessionStore($cache))->exists(Uuid::v4()));
    }

    public function testWriteStoresThePrefixedKeyWithTheTtl(): void
    {
        $id = Uuid::v4();

        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($this->once())->method('set')->with('data')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(60)->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())->method('getItem')->with('sessions.'.$id)->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item)->willReturn(true);

        $this->assertTrue((new Psr6SessionStore($cache, 'sessions.', 60))->write($id, 'data'));
    }

    public function testReadsSessionsWrittenByThePsr16StoreOnTheSamePool(): void
    {
        if (InstalledVersions::satisfies(new VersionParser(), 'symfony/cache', '<6.0') && InstalledVersions::satisfies(new VersionParser(), 'psr/simple-cache', '^3.0')) {
            $this->markTestSkipped('The Psr16Cache of symfony/cache 5.4 does not support psr/simple-cache 3.');
        }

        $pool = new ArrayAdapter();
        $id = Uuid::v4();

        (new Psr16SessionStore(new Psr16Cache($pool)))->write($id, 'data');
        $store = new Psr6SessionStore($pool);

        $this->assertTrue($store->exists($id));
        $this->assertSame('data', $store->read($id));
        $this->assertTrue($store->destroy($id));
        $this->assertFalse($store->read($id));
    }
}
