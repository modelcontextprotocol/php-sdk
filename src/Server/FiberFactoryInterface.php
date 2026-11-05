<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

/**
 * Creates handler fibers with the caller's execution context.
 *
 * @author bbdu3 <ergouyang854@gamil.com>
 */
interface FiberFactoryInterface
{
    /**
     * Returns an unstarted fiber invoking the callback once, preserving its return value, exceptions and suspensions.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     *
     * @return \Fiber<mixed, mixed, TReturn, mixed>
     */
    public function create(\Closure $callback): \Fiber;
}
