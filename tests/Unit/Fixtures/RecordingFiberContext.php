<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Fixtures;

use Mcp\Exception\LogicException;
use Mcp\Server\Fiber\FiberContextInterface;

/**
 * @author bbdu3 <ergouyang854@gmail.com>
 */
final class RecordingFiberContext implements FiberContextInterface
{
    public string $parent = 'parent';
    public int $created = 0;
    public int $released = 0;
    public ?\Throwable $wrapFailure = null;

    /** @var \WeakMap<object, string> */
    private \WeakMap $contexts;

    public function __construct()
    {
        $this->contexts = new \WeakMap();
    }

    public function wrap(\Closure $handler): \Closure
    {
        if (null !== $this->wrapFailure) {
            throw $this->wrapFailure;
        }

        $context = $this->current();
        ++$this->created;

        return function () use ($context, $handler) {
            $this->contexts[\Fiber::getCurrent() ?? throw new LogicException('No active fiber.')] = $context;
            try {
                return $handler();
            } finally {
                unset($this->contexts[\Fiber::getCurrent()]);
                ++$this->released;
            }
        };
    }

    public function current(): string
    {
        $fiber = \Fiber::getCurrent();

        return null === $fiber ? $this->parent : ($this->contexts[$fiber] ?? $this->parent);
    }
}
