<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Intended for SDK internal event listeners.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @var array <class-string, callable[]>
     */
    private array $listeners = [];

    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        if (isset($this->listeners[$event::class])) {
            foreach ($this->listeners[$event::class] as $listener) {
                yield $listener;
            }
        }
    }
}
