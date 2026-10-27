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
 * Settles once a handler fiber the runtime suspended is the server's again: suspended for it, or returned.
 *
 * {@see FiberSchedulerInterface::wait()} waits on it.
 */
final class FiberSettlement
{
    private bool $settled = false;

    /** @var list<\Closure(): void> */
    private array $callbacks = [];

    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * Calls $callback once settled, right away when it already is.
     *
     * @param \Closure(): void $callback
     */
    public function then(\Closure $callback): void
    {
        if ($this->settled) {
            $callback();

            return;
        }

        $this->callbacks[] = $callback;
    }

    /**
     * @internal called by the server, from the handler fiber
     */
    public function settle(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
