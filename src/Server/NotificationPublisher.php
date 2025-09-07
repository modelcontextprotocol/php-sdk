<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Notification;

/**
 * @author Aggelos Bellos <aggelosbellos7@gmail.com>
 */
class NotificationPublisher
{
    /** @var list<Notification> */
    private array $queue = [];

    public function __construct(
        private readonly MessageFactory $factory,
    ) {
    }

    public static function make(): self
    {
        return new self(MessageFactory::make());
    }

    /**
     * @param class-string<Notification> $notificationType
     */
    public function enqueue(string $notificationType): void
    {
        $notification = $this->factory->createByType($notificationType, []);

        $this->queue[] = $notification;
    }

    /**
     * @return iterable<Notification>
     */
    public function flush(): iterable
    {
        if (!$this->queue) {
            return [];
        }

        $out = $this->queue;
        $this->queue = [];

        return $out;
    }
}
