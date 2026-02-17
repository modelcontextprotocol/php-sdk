<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Default implementation of SessionFactoryInterface.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SessionManager implements SessionManagerInterface
{
    public function __construct(
        private readonly SessionStoreInterface $store,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(): SessionInterface
    {
        return new Session($this->store, Uuid::v4());
    }

    public function createWithId(Uuid $id): SessionInterface
    {
        return new Session($this->store, $id);
    }

    public function exists(Uuid $id): bool
    {
        return $this->store->exists($id);
    }

    public function destroy(Uuid $sessionId): bool
    {
        return $this->store->destroy($sessionId);
    }

    /**
     * Run garbage collection on expired sessions.
     * Uses the session store's internal TTL configuration.
     */
    public function gc(): void
    {
        if (random_int(0, 100) > 1) {
            return;
        }

        $deletedSessions = $this->store->gc();
        if (!empty($deletedSessions)) {
            $this->logger->debug('Garbage collected expired sessions.', [
                'count' => \count($deletedSessions),
                'session_ids' => array_map(static fn (Uuid $id) => $id->toRfc4122(), $deletedSessions),
            ]);
        }
    }
}
