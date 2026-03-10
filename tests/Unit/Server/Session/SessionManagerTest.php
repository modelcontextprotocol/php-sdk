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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    public function testGcDisabledWhenProbabilityIsZero(): void
    {
        $store = $this->createMock(InMemorySessionStore::class);
        $store->expects($this->never())->method('gc');

        $manager = new SessionManager($store, gcProbability: 0);

        // Call gc many times — it should never trigger
        for ($i = 0; $i < 100; ++$i) {
            $manager->gc();
        }
    }

    public function testGcAlwaysRunsWhenProbabilityEqualsDivisor(): void
    {
        $store = $this->createMock(InMemorySessionStore::class);
        $store->expects($this->exactly(10))->method('gc')->willReturn([]);

        $manager = new SessionManager($store, gcProbability: 1, gcDivisor: 1);

        for ($i = 0; $i < 10; ++$i) {
            $manager->gc();
        }
    }

    public function testGcAlwaysRunsWhenProbabilityExceedsDivisor(): void
    {
        $store = $this->createMock(InMemorySessionStore::class);
        $store->expects($this->exactly(5))->method('gc')->willReturn([]);

        $manager = new SessionManager($store, gcProbability: 100, gcDivisor: 1);

        for ($i = 0; $i < 5; ++$i) {
            $manager->gc();
        }
    }

    public function testGcProbabilityMustBeNonNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gcProbability must be greater than or equal to 0.');

        new SessionManager(new InMemorySessionStore(), gcProbability: -1);
    }

    public function testGcDivisorMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gcDivisor must be greater than or equal to 1.');

        new SessionManager(new InMemorySessionStore(), gcDivisor: 0);
    }

    public function testDefaultGcConfiguration(): void
    {
        // Default should be 1/100 — just verify construction works
        $manager = new SessionManager(new InMemorySessionStore());
        $this->assertInstanceOf(SessionManager::class, $manager);
    }
}
