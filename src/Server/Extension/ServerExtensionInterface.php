<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Extension;

use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * An extension that answers the requests and receives the notifications it
 * defines when enabled on a server.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ServerExtensionInterface extends ExtensionInterface
{
    /**
     * The handlers serving the methods of {@see self::getMessages()}.
     *
     * @return iterable<RequestHandlerInterface<ResultInterface>>
     */
    public function getRequestHandlers(): iterable;

    /**
     * The handlers receiving the notifications of {@see self::getMessages()}.
     *
     * @return iterable<NotificationHandlerInterface>
     */
    public function getNotificationHandlers(): iterable;
}
