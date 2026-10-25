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
 * The runtime that may suspend a request handler's fiber besides the server: an event loop.
 *
 * The server runs every request handler in a fiber, so that the handler can wait on the client
 * ({@see \Mcp\Server\ClientGateway}). Under an event loop — Revolt (AMPHP), ReactPHP's async, any fiber
 * scheduler — the handler's own I/O suspends that same fiber, for the loop: the server must not resume
 * it then, the loop will. The server hands control to the scheduler instead, until the handler suspends
 * for the server again or returns.
 *
 * Without a scheduler, a handler fiber suspended by anything but the server fails the request.
 */
interface FiberSchedulerInterface
{
    /**
     * Lets the runtime run until $settlement settles, then returns.
     *
     * Called on the fiber — or `{main}` — that drives the handler, never while $settlement is already
     * settled. It settles from inside the handler fiber, once the handler suspended for the server or
     * returned; implement it with the runtime's own way of waiting for a callback.
     */
    public function wait(FiberSettlement $settlement): void;
}
