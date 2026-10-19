<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client\MonotonicClock;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class MonotonicClockTest extends TestCase
{
    #[TestDox('starts at the current wall time and never moves backwards')]
    public function testMonotonic(): void
    {
        $before = microtime(true);
        $clock = new MonotonicClock();

        $previous = $clock->now();
        $this->assertEqualsWithDelta($before, (float) $previous->format('U.u'), 1.0);

        for ($i = 0; $i < 1_000; ++$i) {
            $now = $clock->now();
            $this->assertGreaterThanOrEqual($previous, $now);
            $previous = $now;
        }
    }
}
