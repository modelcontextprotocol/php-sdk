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

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements TransportInterface<null>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InMemoryTransport extends BaseTransport implements TransportInterface
{
    /**
     * @param list<string> $messages
     */
    public function __construct(
        private readonly array $messages = [],
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }
    }

    /**
     * @return null
     */
    public function listen(): mixed
    {
        $this->logger->info('InMemoryTransport is processing messages...');

        foreach ($this->messages as $message) {
            $this->handleMessage($message, $this->sessionId);
        }

        $this->logger->info('InMemoryTransport finished processing.');
        $this->handleSessionEnd($this->sessionId);

        $this->sessionId = null;

        return null;
    }

    public function setSessionId(?Uuid $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function close(): void
    {
        $this->handleSessionEnd($this->sessionId);
        $this->sessionId = null;
    }
}
