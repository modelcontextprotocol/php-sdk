<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Fiber;

use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;

/**
 * The smallest event loop: what a handler awaits suspends its fiber with nothing, as Revolt does, and the
 * loop resumes it with the result on a later turn. Unlike a real loop, wait() runs its turns on the stack
 * of the fiber that drives the handler.
 */
final class FakeEventLoop implements FiberSchedulerInterface
{
    /** @var list<\Closure(): void> */
    private array $ready = [];

    public int $waits = 0;

    /**
     * Awaits $result like I/O: the current fiber is suspended for the loop until a turn resumes it.
     */
    public function await(mixed $result): mixed
    {
        $fiber = \Fiber::getCurrent() ?? throw new \LogicException('Awaiting needs a fiber.');
        $this->ready[] = static function () use ($fiber, $result): void {
            $fiber->resume($result);
        };

        return \Fiber::suspend();
    }

    /**
     * Runs the turns left, as a loop does whether or not the server still waits on the fiber.
     */
    public function drain(): void
    {
        while (null !== $turn = array_shift($this->ready)) {
            $turn();
        }
    }

    public function wait(FiberSettlement $settlement): void
    {
        ++$this->waits;

        while (!$settlement->isSettled()) {
            $turn = array_shift($this->ready) ?? throw new \LogicException('Nothing left to run: the fiber never settles.');
            $turn();
        }
    }
}
