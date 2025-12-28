<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Mcp\Client\Session\ClientSessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Base implementation for client transports.
 *
 * Provides callback management and common utilities.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class BaseTransport implements TransportInterface
{
    /** @var callable(): mixed|null */
    protected $initializeCallback;

    /** @var callable(string): void|null */
    protected $messageCallback;

    /** @var callable(\Throwable): void|null */
    protected $errorCallback;

    /** @var callable(string): void|null */
    protected $closeCallback;

    protected ?ClientSessionInterface $session = null;
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function onInitialize(callable $listener): void
    {
        $this->initializeCallback = $listener;
    }

    public function onMessage(callable $listener): void
    {
        $this->messageCallback = $listener;
    }

    public function onError(callable $listener): void
    {
        $this->errorCallback = $listener;
    }

    public function onClose(callable $listener): void
    {
        $this->closeCallback = $listener;
    }

    public function setSession(ClientSessionInterface $session): void
    {
        $this->session = $session;
    }

    /**
     * Perform initialization via the registered callback.
     *
     * @return mixed The result from the initialization callback
     *
     * @throws \RuntimeException If no initialize listener is registered
     */
    protected function handleInitialize(): mixed
    {
        if (!\is_callable($this->initializeCallback)) {
            throw new \RuntimeException('No initialize listener registered');
        }

        return ($this->initializeCallback)();
    }

    /**
     * Handle an incoming message from the server.
     */
    protected function handleMessage(string $message): void
    {
        if (\is_callable($this->messageCallback)) {
            try {
                ($this->messageCallback)($message);
            } catch (\Throwable $e) {
                $this->handleError($e);
            }
        }
    }

    /**
     * Handle a transport error.
     */
    protected function handleError(\Throwable $error): void
    {
        $this->logger->error('Transport error', ['exception' => $error]);

        if (\is_callable($this->errorCallback)) {
            ($this->errorCallback)($error);
        }
    }

    /**
     * Handle connection close.
     */
    protected function handleClose(string $reason): void
    {
        $this->logger->info('Transport closed', ['reason' => $reason]);

        if (\is_callable($this->closeCallback)) {
            ($this->closeCallback)($reason);
        }
    }
}
