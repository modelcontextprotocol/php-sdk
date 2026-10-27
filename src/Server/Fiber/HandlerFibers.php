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

use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;

/**
 * Creates, starts, resumes and suspends the fibers request handlers run in.
 *
 * A handler fiber suspends for the server to wait on the client ({@see \Mcp\Server\ClientGateway}): the
 * server keeps what it suspended with and later resumes it with the answer. Under an event loop, the
 * handler's own I/O suspends that same fiber for the loop: resuming it then would hand the loop's await
 * a value it never asked for. Starting or resuming a fiber here therefore returns only once it suspended
 * for the server again, or returned; in between, the runtime's {@see FiberSchedulerInterface} runs, and
 * resumes the fiber itself.
 *
 * A fiber not created here is started and resumed as is. Under an event loop, a handler waits on the client
 * through the gateway: a payload it suspends with itself, after the loop resumed it, would reach the loop.
 *
 * @internal
 */
final class HandlerFibers
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, HandlerFiberState>|null */
    private static ?\WeakMap $states = null;

    /**
     * A fiber running $handler, driven through this class.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $handler
     *
     * @return \Fiber<mixed, mixed, TReturn, mixed>
     */
    public static function create(\Closure $handler, ?FiberSchedulerInterface $scheduler = null): \Fiber
    {
        $state = new HandlerFiberState($scheduler);
        $fiber = new \Fiber(static function () use ($handler, $state): mixed {
            try {
                return $handler();
            } catch (\Throwable $e) {
                // Thrown while the runtime drives the fiber, it would escape into the runtime: the server rethrows it.
                $state->threw($e);

                return null;
            } finally {
                $state->ended();
            }
        });
        self::states()[$fiber] = $state;

        return $fiber;
    }

    /**
     * Starts $fiber; returns what it suspended with for the server, or null once it returned.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public static function start(\Fiber $fiber): mixed
    {
        return self::step($fiber, static fn (): mixed => $fiber->start());
    }

    /**
     * Resumes $fiber with $value; returns what it suspended with for the server next, or null once it returned.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public static function resume(\Fiber $fiber, mixed $value = null): mixed
    {
        return self::step($fiber, static fn (): mixed => $fiber->resume($value));
    }

    /**
     * Suspends the current handler fiber for the server, until the server resumes it: how a handler waits on the client.
     */
    public static function suspend(object $suspension): mixed
    {
        $fiber = \Fiber::getCurrent();
        if (null !== $fiber) {
            (self::states()[$fiber] ?? null)?->parked($suspension);
        }

        return \Fiber::suspend($suspension);
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     * @param \Closure(): mixed                  $step
     */
    private static function step(\Fiber $fiber, \Closure $step): mixed
    {
        $state = self::states()[$fiber] ?? null;
        if (null === $state) {
            return $step();
        }

        $state->unparked();
        $suspended = $step();

        // Suspended straight with a server payload, without the gateway: for the server too.
        if ($fiber->isSuspended() && !$state->isParked() && ($suspended instanceof NotificationSuspension || $suspended instanceof RequestSuspension)) {
            $state->parked($suspended);
        }

        while ($fiber->isSuspended() && !$state->isParked()) {
            $state->waitForTheRuntime();
        }

        if ($fiber->isTerminated()) {
            $state->rethrow();

            return null;
        }

        return $state->suspension() ?? $suspended;
    }

    /**
     * @return \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, HandlerFiberState>
     */
    private static function states(): \WeakMap
    {
        return self::$states ??= new \WeakMap();
    }
}
