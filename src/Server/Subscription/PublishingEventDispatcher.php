<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Subscription;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes registry change events onto a notification bus, then passes them on.
 *
 * PSR-14 splits dispatching from listener registration, and the SDK is only
 * handed the dispatcher half — so it cannot register a listener on an
 * application's own dispatcher. This decorator is the way round that: configure
 * a bus and the SDK wraps whatever dispatcher it was given (or stands in for
 * one), so a runtime `registerTool()` reaches a listening client without the
 * caller wiring anything.
 *
 * An application with its own listener provider can skip this and register
 * {@see RegistryChangePublisher::listeners()} itself.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PublishingEventDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, callable(object): void> */
    private readonly array $listeners;

    public function __construct(
        NotificationBusInterface $bus,
        private readonly ?EventDispatcherInterface $inner = null,
    ) {
        $this->listeners = (new RegistryChangePublisher($bus))->listeners();
    }

    public function dispatch(object $event): object
    {
        $listener = $this->listeners[$event::class] ?? null;

        if (null !== $listener) {
            $listener($event);
        }

        return $this->inner?->dispatch($event) ?? $event;
    }
}
