<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\Loopback;

use Mcp\Server\Transport\BaseTransport;
use Psr\Log\LoggerInterface;

/**
 * Server transport handing its output straight to the client in the same process.
 *
 * Mirrors what {@see \Mcp\Server\Transport\StdioTransport} does per loop pass —
 * resume the session Fiber, flush the outgoing queue — except that the loop
 * belongs to {@see LoopbackConnection}, which calls {@see self::pump()} until
 * nothing moves.
 *
 * @extends BaseTransport<null>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class LoopbackServerTransport extends BaseTransport
{
    public function __construct(
        private readonly LoopbackConnection $connection,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
    }

    /**
     * The connection drives this transport, so there is no loop to run here.
     */
    public function listen(): mixed
    {
        return null;
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }

        $this->connection->toClient($data);
    }

    /**
     * Hand a client message to the protocol.
     */
    public function deliver(string $payload): void
    {
        $this->handleMessage($payload, $this->sessionId);
    }

    /**
     * Advance the session Fiber and flush whatever the protocol queued.
     *
     * @return bool whether anything moved, which is what tells the connection to keep draining
     */
    public function pump(): bool
    {
        $progressed = $this->advanceFiber();

        foreach ($this->getOutgoingMessages($this->sessionId) as $message) {
            $this->connection->toClient($message['message']);
            $progressed = true;
        }

        return $progressed;
    }

    private function advanceFiber(): bool
    {
        if (null === $this->sessionFiber) {
            return false;
        }

        if ($this->sessionFiber->isTerminated()) {
            $this->finishFiber();

            return true;
        }

        if (!$this->sessionFiber->isSuspended()) {
            return false;
        }

        $pendingRequests = $this->getPendingRequests($this->sessionId);

        if ([] === $pendingRequests) {
            $this->handleFiberYield($this->sessionFiber->resume(), $this->sessionId);

            return true;
        }

        foreach ($pendingRequests as $pending) {
            \assert(\is_int($pending['request_id']));

            $response = $this->checkForResponse($pending['request_id'], $this->sessionId);

            if (null !== $response) {
                $this->handleFiberYield($this->sessionFiber->resume($response), $this->sessionId);

                return true;
            }
        }

        // Still waiting on the client. Unlike the stdio transport there is no
        // timeout to expire here: nothing runs concurrently, so a response that
        // has not arrived by now is never going to. The connection settles and
        // the client transport reports the stall instead.
        return false;
    }

    private function finishFiber(): void
    {
        $result = $this->sessionFiber?->getReturn();
        $this->sessionFiber = null;

        if (null === $result) {
            return;
        }

        try {
            $this->connection->toClient(json_encode($result, \JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            $this->logger->error('Failed to encode the final Fiber result.', ['exception' => $e]);
        }
    }
}
