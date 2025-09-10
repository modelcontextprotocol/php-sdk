<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Server\NotificationHandler;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Server\MethodHandlerInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializedHandler implements MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof InitializedNotification;
    }

    public function handle(InitializedNotification|HasMethodInterface $message): Response|Error|null
    {
        return null;
    }
}
