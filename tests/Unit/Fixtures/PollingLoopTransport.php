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

use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Server\Transport\TransportInterface;

/**
 * Exposes the protocol callbacks a transport's polling loop calls, to test which client requests
 * one stream waits on while others share its session.
 *
 * @phpstan-import-type FiberSuspend from TransportInterface
 */
final class PollingLoopTransport extends InMemoryTransport
{
    /**
     * @return list<int>
     */
    public function getPendingRequestIds(): array
    {
        return array_keys($this->getPendingRequests($this->sessionId));
    }

    /**
     * @param FiberSuspend $yielded
     */
    public function yieldFromFiber(NotificationSuspension|RequestSuspension $yielded): void
    {
        $this->handleFiberYield($yielded, $this->sessionId);
    }
}
