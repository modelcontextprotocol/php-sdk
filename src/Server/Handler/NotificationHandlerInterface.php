<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler;

use Mcp\Schema\JsonRpc\Notification;

/**
 * Interface for handling notification creation.
 *
 * Notification handlers are responsible for creating outgoing notifications
 * that the server sends to clients, as opposed to incoming JSON-RPC requests.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
interface NotificationHandlerInterface
{
    /**
     * Creates a notification instance from the given parameters.
     *
     * @param string               $method The notification method
     * @param array<string, mixed> $params Parameters for the notification
     *
     * @return Notification The created notification instance
     */
    public function handle(string $method, array $params): Notification;
}
