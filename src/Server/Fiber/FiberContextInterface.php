<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Fiber;

/**
 * Carries execution context, e.g. the active OpenTelemetry context, into the fibers request handlers run in.
 *
 * @author bbdu3 <ergouyang854@gamil.com>
 */
interface FiberContextInterface
{
    /**
     * Called in the context of the caller. The returned closure runs in the handler fiber and must call $handler once and return its result.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $handler
     *
     * @return \Closure(): TReturn
     */
    public function wrap(\Closure $handler): \Closure;
}
