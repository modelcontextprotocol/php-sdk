<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a skeletal implementation of the TransportInterface to minimize
 * the effort required to implement this interface.
 *
 * @phpstan-import-type FiberResume from TransportInterface
 * @phpstan-import-type FiberReturn from TransportInterface
 * @phpstan-import-type FiberSuspend from TransportInterface
 * @phpstan-import-type McpFiber from TransportInterface
 *
 * @template TResult
 *
 * @implements TransportInterface<TResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class BaseTransport implements TransportInterface
{
    use ManagesTransportCallbacks;

    protected ?Uuid $sessionId = null;

    /**
     * @var McpFiber|null
     */
    protected ?\Fiber $sessionFiber = null;

    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function initialize(): void
    {
    }

    public function close(): void
    {
    }

    public function setSessionId(?Uuid $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * @param McpFiber $fiber
     */
    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
    {
        $this->sessionFiber = $fiber;
        $this->sessionId = $sessionId;
    }

    /**
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    protected function getOutgoingMessages(?Uuid $sessionId): array
    {
        if ($sessionId && \is_callable($this->outgoingMessagesProvider)) {
            return ($this->outgoingMessagesProvider)($sessionId);
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPendingRequests(?Uuid $sessionId): array
    {
        if ($sessionId && \is_callable($this->pendingRequestsProvider)) {
            return ($this->pendingRequestsProvider)($sessionId);
        }

        return [];
    }

    /**
     * @phpstan-return FiberResume
     */
    protected function checkForResponse(int $requestId, ?Uuid $sessionId): Response|Error|null
    {
        if ($sessionId && \is_callable($this->responseFinder)) {
            return ($this->responseFinder)($requestId, $sessionId);
        }

        return null;
    }

    /**
     * @param FiberSuspend|null $yielded
     */
    protected function handleFiberYield(mixed $yielded, ?Uuid $sessionId): void
    {
        if (null === $yielded || !\is_callable($this->fiberYieldHandler)) {
            return;
        }

        try {
            ($this->fiberYieldHandler)($yielded, $sessionId);
        } catch (\Throwable $e) {
            $this->logger->error('Fiber yield handler failed.', [
                'exception' => $e,
                'sessionId' => $sessionId?->toRfc4122(),
            ]);
        }
    }

    protected function handleMessage(string $payload, ?Uuid $sessionId): void
    {
        if (\is_callable($this->messageListener)) {
            ($this->messageListener)($this, $payload, $sessionId);
        }
    }

    protected function handleSessionEnd(?Uuid $sessionId): void
    {
        if ($sessionId && \is_callable($this->sessionEndListener)) {
            ($this->sessionEndListener)($sessionId);
        }
    }
}
