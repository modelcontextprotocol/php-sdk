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
use Mcp\Server\FiberFactoryInterface;

/**
 * @author bbdu3 <ergouyang854@gamil.com>
 */
final class ContextFiberFactory implements FiberFactoryInterface
{
    public string $parent = 'parent';
    public int $created = 0;
    public int $released = 0;

    /** @var \WeakMap<object, string> */
    private \WeakMap $contexts;

    public function __construct()
    {
        $this->contexts = new \WeakMap();
    }

    public function create(\Closure $callback): \Fiber
    {
        $context = $this->current();
        ++$this->created;

        return new \Fiber(function () use ($context, $callback) {
            $this->contexts[\Fiber::getCurrent() ?? throw new LogicException('No active fiber.')] = $context;
            try {
                return $callback();
            } finally {
                unset($this->contexts[\Fiber::getCurrent()]);
                ++$this->released;
            }
        });
    }

    public function current(): string
    {
        $fiber = \Fiber::getCurrent();

        return null === $fiber ? $this->parent : $this->contexts[$fiber];
    }
}
