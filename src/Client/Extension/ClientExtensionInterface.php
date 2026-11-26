<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Extension;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * An extension that answers the requests and receives the notifications it
 * defines when enabled on a client.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface ClientExtensionInterface extends ExtensionInterface
{
    /**
     * The handlers answering the server requests of {@see self::getMessages()}.
     *
     * @return iterable<RequestHandlerInterface<ResultInterface>>
     */
    public function getRequestHandlers(): iterable;

    /**
     * The handlers receiving the server notifications of {@see self::getMessages()}.
     *
     * @return iterable<NotificationHandlerInterface>
     */
    public function getNotificationHandlers(): iterable;
}
