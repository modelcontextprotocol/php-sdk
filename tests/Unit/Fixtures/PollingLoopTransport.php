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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Server\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Exposes the protocol callbacks a transport's polling loop calls, to test which client requests
 * one stream waits on while others share its session.
 *
 * @phpstan-import-type FiberSuspend from TransportInterface
 * @phpstan-import-type McpFiber from TransportInterface
 */
final class PollingLoopTransport extends InMemoryTransport
{
    /**
     * @return list<string>
     */
    public function getPendingRequestIds(): array
    {
        \assert(null !== $this->sessionFiber);

        return array_keys($this->getPendingRequests($this->sessionId, $this->sessionFiber));
    }

    /**
     * @return array<int, array<mixed>> the messages this stream sends the client next, decoded
     */
    public function takeOutgoingMessages(): array
    {
        return array_map(static fn (array $message): array => json_decode($message['message'], true), $this->getOutgoingMessages($this->sessionId));
    }

    /**
     * @param FiberSuspend $yielded
     */
    public function yieldFromFiber(NotificationSuspension|RequestSuspension $yielded): void
    {
        \assert(null !== $this->sessionFiber);

        $this->handleFiberYield($yielded, $this->sessionId, $this->sessionFiber);
    }

    /**
     * @return McpFiber|null
     */
    public function getSessionFiber(): ?\Fiber
    {
        return $this->sessionFiber;
    }

    /**
     * One turn of a handshake-era polling loop: drop a cancelled fiber, or resume
     * it when its answer is in, and return what it would have sent the client.
     *
     * @return Response<mixed>|Error|null
     */
    public function pump(): Response|Error|null
    {
        $fiber = $this->sessionFiber;
        if (null === $fiber) {
            return null;
        }

        if ($this->isRequestCancelled($fiber)) {
            $this->sessionFiber = null;

            return null;
        }

        if ($fiber->isSuspended()) {
            $pendingRequests = $this->getPendingRequests($this->sessionId, $fiber);

            if ([] === $pendingRequests) {
                if (null !== $error = $this->resumeHandlerFiber($fiber, $this->sessionId)) {
                    $this->sessionFiber = null;

                    return $error;
                }
            } else {
                foreach ($pendingRequests as $pending) {
                    $response = $this->checkForResponse(Uuid::fromString($pending['request_id']), $this->sessionId);
                    if (null === $response) {
                        continue;
                    }

                    if (null !== $error = $this->resumeHandlerFiber($fiber, $this->sessionId, $response)) {
                        $this->sessionFiber = null;

                        return $error;
                    }

                    break;
                }
            }
        }

        if ($fiber->isTerminated()) {
            $this->sessionFiber = null;
            if ($this->isRequestCancelled($fiber)) {
                return null;
            }

            $result = $fiber->getReturn();

            return $result instanceof Response || $result instanceof Error ? $result : null;
        }

        return null;
    }
}
