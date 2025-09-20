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

use Mcp\Server\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InMemoryTransport implements TransportInterface
{
    private bool $connected = true;
    private $messageListener = null;
    private $sessionDestroyListener = null;
    private ?Uuid $sessionId = null;

    /**
     * @param list<string> $messages
     */
    public function __construct(
        private readonly array $messages = [],
    ) {}

    public function initialize(): void {}

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

    public function listen(): mixed
    {
        foreach ($this->messages as $message) {
            if (is_callable($this->messageListener)) {
                call_user_func($this->messageListener, $message, $this->sessionId);
            }
        }

        $this->connected = false;

        if (is_callable($this->sessionDestroyListener) && $this->sessionId !== null) {
            call_user_func($this->sessionDestroyListener, $this->sessionId);
        }

        return null;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionDestroyListener = $listener;
    }

    public function close(): void
    {
        if (is_callable($this->sessionDestroyListener) && $this->sessionId !== null) {
            call_user_func($this->sessionDestroyListener, $this->sessionId);
        }
    }
}
