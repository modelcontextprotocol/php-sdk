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

use Mcp\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Default implementation of SessionManagerInterface.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SessionManager implements SessionManagerInterface
{
    /**
     * @param int $gcProbability The probability (numerator) that GC will run on any given request. Combined with $gcDivisor to calculate the actual probability. Set to 0 to disable GC. Similar to PHP's session.gc_probability.
     * @param int $gcDivisor     The divisor used with $gcProbability to calculate GC probability. The probability is gcProbability/gcDivisor (e.g. 1/100 = 1%). Similar to PHP's session.gc_divisor.
     */
    public function __construct(
        private readonly SessionStoreInterface $store,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $gcProbability = 1,
        private readonly int $gcDivisor = 100,
    ) {
        if ($gcProbability < 0) {
            throw new InvalidArgumentException('gcProbability must be greater than or equal to 0.');
        }
        if ($gcDivisor < 1) {
            throw new InvalidArgumentException('gcDivisor must be greater than or equal to 1.');
        }
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

    public function destroy(Uuid $id): bool
    {
        return $this->store->destroy($id);
    }

    /**
     * Run garbage collection on expired sessions.
     * Uses the session store's internal TTL configuration.
     */
    public function gc(): void
    {
        if (0 === $this->gcProbability) {
            return;
        }

        if (random_int(1, $this->gcDivisor) > $this->gcProbability) {
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
