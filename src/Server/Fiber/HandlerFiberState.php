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

use Mcp\Exception\LogicException;

/**
 * What the server knows of one handler fiber: whether, and on what, it is suspended for the server.
 *
 * @internal
 */
final class HandlerFiberState
{
    private bool $parked = false;

    private ?object $suspension = null;

    private ?FiberSettlement $settlement = null;

    private ?\Throwable $throwable = null;

    public function __construct(
        private readonly ?FiberSchedulerInterface $scheduler,
    ) {
    }

    public function isParked(): bool
    {
        return $this->parked;
    }

    public function suspension(): ?object
    {
        return $this->suspension;
    }

    /**
     * The handler suspended for the server, on $suspension.
     */
    public function parked(object $suspension): void
    {
        $this->parked = true;
        $this->suspension = $suspension;
        $this->settlement?->settle();
    }

    /**
     * The server resumes the fiber: until it suspends for the server again, it is not.
     */
    public function unparked(): void
    {
        $this->parked = false;
        $this->suspension = null;
    }

    public function threw(\Throwable $throwable): void
    {
        $this->throwable = $throwable;
    }

    public function ended(): void
    {
        $this->settlement?->settle();
    }

    /**
     * Rethrows, once, what the handler threw.
     */
    public function rethrow(): void
    {
        $throwable = $this->throwable;
        $this->throwable = null;

        if (null !== $throwable) {
            throw $throwable;
        }
    }

    public function waitForTheRuntime(): void
    {
        if (null === $this->scheduler) {
            throw new LogicException('A request handler suspended its fiber for something other than the server, an event loop awaiting I/O for instance: set the runtime\'s fiber scheduler with Builder::setFiberScheduler().');
        }

        $this->settlement = new FiberSettlement();
        try {
            $this->scheduler->wait($this->settlement);
        } finally {
            $this->settlement = null;
        }
    }
}
