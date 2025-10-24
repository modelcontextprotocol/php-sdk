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

use Symfony\Component\Uid\Uuid;

/**
 * @implements TransportInterface<null>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InMemoryTransport extends BaseTransport implements TransportInterface
{
    use ManagesTransportCallbacks;

    /**
     * @param list<string> $messages
     */
    public function __construct(
        private readonly array $messages = [],
    ) {
    }

    public function initialize(): void
    {
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
        foreach ($this->messages as $message) {
            $this->handleMessage($message, $this->sessionId);
        }

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
