<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\ReactPhp;

use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;

/**
 * Lets the event loop run while a request handler awaits I/O.
 */
final class ReactFiberScheduler implements FiberSchedulerInterface
{
    public function wait(FiberSettlement $settlement): void
    {
        $deferred = new Deferred();

        // await() resumes as soon as the promise resolves, and the handler fiber still runs at this point.
        $settlement->then(static function () use ($deferred): void {
            Loop::futureTick(static fn () => $deferred->resolve(null));
        });

        await($deferred->promise());
    }
}
