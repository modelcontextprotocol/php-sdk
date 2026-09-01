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

use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Event\PromptListChangedEvent;
use Mcp\Server\Event\ResourceListChangedEvent;
use Mcp\Server\Event\ResourceTemplateListChangedEvent;
use Mcp\Server\Event\ToolListChangedEvent;

/**
 * Turns the registry's own change events into the notifications a
 * `subscriptions/listen` stream carries.
 *
 * The registry already announces every mutation to the PSR-14 dispatcher; this
 * is the adapter that puts them where a stream can read them, so a runtime
 * `registerTool()` reaches a listening client without the caller knowing a bus
 * exists.
 *
 * There is no template-list notification in the protocol, so a changed
 * resource-template list is announced as a changed resource list — which is
 * what a client would refetch anyway.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RegistryChangePublisher
{
    public function __construct(
        private readonly NotificationBusInterface $bus,
    ) {
    }

    public function onToolListChanged(ToolListChangedEvent $event): void
    {
        $this->bus->publish(new ToolListChangedNotification());
    }

    public function onPromptListChanged(PromptListChangedEvent $event): void
    {
        $this->bus->publish(new PromptListChangedNotification());
    }

    public function onResourceListChanged(ResourceListChangedEvent $event): void
    {
        $this->bus->publish(new ResourceListChangedNotification());
    }

    public function onResourceTemplateListChanged(ResourceTemplateListChangedEvent $event): void
    {
        $this->bus->publish(new ResourceListChangedNotification());
    }

    /**
     * Every event this publisher handles, keyed by event class.
     *
     * Shaped for a dispatcher that wants a map; a framework's own subscriber
     * conventions can read it too rather than restating the list.
     *
     * @return array<class-string, callable(object): void>
     */
    public function listeners(): array
    {
        return [
            ToolListChangedEvent::class => $this->onToolListChanged(...),
            PromptListChangedEvent::class => $this->onPromptListChanged(...),
            ResourceListChangedEvent::class => $this->onResourceListChanged(...),
            ResourceTemplateListChangedEvent::class => $this->onResourceTemplateListChanged(...),
        ];
    }
}
