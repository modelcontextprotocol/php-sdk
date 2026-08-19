<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

use Psr\Clock\ClockInterface;

/**
 * A clock that never moves backwards.
 *
 * Anchored at the wall time of its creation and advanced by hrtime(), so
 * scheduled delays are not stretched or skipped when the system clock is
 * adjusted.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class MonotonicClock implements ClockInterface
{
    private readonly float $startedAt;
    private readonly int|float $startedAtNs;

    public function __construct()
    {
        $this->startedAt = microtime(true);
        $this->startedAtNs = hrtime(true);
    }

    public function now(): \DateTimeImmutable
    {
        $seconds = $this->startedAt + (hrtime(true) - $this->startedAtNs) / 1e9;

        return new \DateTimeImmutable(\sprintf('@%.6F', $seconds));
    }
}
