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

use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;
use Revolt\EventLoop;

/**
 * Lets the event loop run while a request handler awaits I/O.
 */
final class RevoltFiberScheduler implements FiberSchedulerInterface
{
    public function wait(FiberSettlement $settlement): void
    {
        $suspension = EventLoop::getSuspension();
        $settlement->then($suspension->resume(...));
        $suspension->suspend();
    }
}
