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

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * The default Subscription manager implementation manages subscriptions per session only.
 * It is in-memory and does not support cross-session or cross-client subscriptions.
 *
 * The SDK allows injecting alternative SubscriptionManagerInterface
 * implementations via Builder::setResourceSubscriptionManager().
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
final class SessionSubscriptionManager implements SubscriptionManagerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function subscribe(SessionInterface $session, string $uri): void
    {
        $subscriptions = $session->get('resource_subscriptions', []);
        $subscriptions[$uri] = true;
        $session->set('resource_subscriptions', $subscriptions);
        $session->save();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function unsubscribe(SessionInterface $session, string $uri): void
    {
        $subscriptions = $session->get('resource_subscriptions', []);
        unset($subscriptions[$uri]);
        $session->set('resource_subscriptions', $subscriptions);
        $session->save();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isSubscribed(SessionInterface $session, string $uri): bool
    {
        $subscriptions = $session->get('resource_subscriptions', []);

        return isset($subscriptions[$uri]);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function notifyResourceChanged(Protocol $protocol, SessionInterface $session, string $uri): void
    {
        $activeSession = $this->isSubscribed($session, $uri);
        if (!$activeSession) {
            return;
        }

        try {
            $protocol->sendNotification(
                new ResourceUpdatedNotification($uri),
                $session
            );
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error sending resource notification to session', [
                'session_id' => $session->getId()->toRfc4122(),
                'uri' => $uri,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
