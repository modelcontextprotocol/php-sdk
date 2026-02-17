<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Resource;

use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Resource subscription interface.
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
interface SubscriptionManagerInterface
{
    /**
     * Subscribes a session to a specific resource URI.
     *
     * @throws InvalidArgumentException
     */
    public function subscribe(SessionInterface $session, string $uri): void;

    /**
     * Unsubscribes a session from a specific resource URI.
     *
     * @throws InvalidArgumentException
     */
    public function unsubscribe(SessionInterface $session, string $uri): void;

    /**
     * Check if a session is subscribed to a resource URI.
     *
     * @throws InvalidArgumentException
     */
    public function isSubscribed(SessionInterface $session, string $uri): bool;

    /**
     * Notifies all sessions subscribed to the given resource URI that the
     * resource has changed. Sends a ResourceUpdatedNotification for each subscriber.
     *
     * @throws InvalidArgumentException
     */
    public function notifyResourceChanged(Protocol $protocol, SessionInterface $session, string $uri): void;
}
