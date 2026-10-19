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

use Mcp\Exception\SessionStoreException;
use Mcp\Server\Session\Psr16SessionStore;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Uuid;

final class Psr16SessionStoreTest extends TestCase
{
    public function testExistsReportsAMissingSession(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturn(false);

        $this->assertFalse((new Psr16SessionStore($cache))->exists(Uuid::v4()));
    }

    public function testExistsThrowsWhenTheCacheIsUnavailable(): void
    {
        $failure = new \RuntimeException('Connection refused');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willThrowException($failure);

        try {
            (new Psr16SessionStore($cache))->exists(Uuid::v4());
            $this->fail('Expected a SessionStoreException.');
        } catch (SessionStoreException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
    }
}
