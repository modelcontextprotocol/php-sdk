<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\Revolt;

use Amp\Future;
use Psr\Log\LoggerInterface;

use function Amp\async;
use function Amp\delay;

/**
 * Tools awaiting I/O on the event loop, simulated with timers.
 */
final class SlowTools
{
    /** @var array<string, float> latency of each simulated service, in seconds */
    private const SERVICES = [
        'database' => 0.3,
        'cache' => 0.1,
        'search' => 0.2,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Waits on the event loop without blocking other requests.
     *
     * @param float $seconds how long to wait, at most 5 seconds
     */
    public function wait(float $seconds): string
    {
        $seconds = max(0.0, min($seconds, 5.0));
        $this->logger->info('Waiting on the event loop.', ['seconds' => $seconds]);

        delay($seconds);

        return \sprintf('Waited %s seconds on the event loop.', $seconds);
    }

    /**
     * Checks all services concurrently, in the time of the slowest one.
     *
     * @return array<string, string> the status of each service
     */
    public function checkServices(): array
    {
        $checks = [];
        foreach (self::SERVICES as $service => $latency) {
            $checks[$service] = async(static function () use ($latency): string {
                delay($latency);

                return 'up';
            });
        }

        return array_map(static fn (Future $check): string => $check->await(), $checks);
    }
}
