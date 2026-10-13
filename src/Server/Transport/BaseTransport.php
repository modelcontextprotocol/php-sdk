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
use Mcp\Server\Authorization\AccessToken;
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

    private const YIELD_FAILED_MESSAGE = 'Failed to send the message to the client.';

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
     * @param McpFiber $fiber
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getPendingRequests(?Uuid $sessionId, \Fiber $fiber): array
    {
        if ($sessionId && \is_callable($this->pendingRequestsProvider)) {
            return ($this->pendingRequestsProvider)($sessionId, $fiber);
        }

        return [];
    }

    /**
     * @phpstan-return FiberResume
     */
    protected function checkForResponse(string $requestId, ?Uuid $sessionId): Response|Error|null
    {
        if ($sessionId && \is_callable($this->responseFinder)) {
            return ($this->responseFinder)($requestId, $sessionId);
        }

        return null;
    }

    /**
     * Hands what the session's fiber yielded to the yield handler.
     *
     * When the handler fails, the message the fiber suspended on never reaches the client, so
     * the fiber is resumed with an error instead of being left waiting on nothing; whatever it
     * yields next is handled the same way.
     *
     * @param FiberSuspend|null $yielded
     * @param McpFiber          $fiber   the fiber that yielded
     */
    protected function handleFiberYield(mixed $yielded, ?Uuid $sessionId, \Fiber $fiber): void
    {
        while (null !== $yielded && \is_callable($this->fiberYieldHandler)) {
            try {
                ($this->fiberYieldHandler)($yielded, $sessionId, $fiber);

                return;
            } catch (\Throwable $e) {
                $this->logger->error('Fiber yield handler failed.', [
                    'exception' => $e,
                    'sessionId' => $sessionId?->toRfc4122(),
                ]);
            }

            if (!$fiber->isSuspended()) {
                return;
            }

            $yielded = $fiber->resume(Error::forInternalError(self::YIELD_FAILED_MESSAGE));
        }
    }

    protected function handleMessage(string $payload, ?Uuid $sessionId, ?AccessToken $accessToken = null): void
    {
        if (\is_callable($this->messageListener)) {
            ($this->messageListener)($this, $payload, $sessionId, $accessToken);
        }
    }

    protected function handleSessionEnd(?Uuid $sessionId): void
    {
        if ($sessionId && \is_callable($this->sessionEndListener)) {
            ($this->sessionEndListener)($sessionId);
        }
    }
}
