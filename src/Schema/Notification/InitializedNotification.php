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

namespace Mcp\Schema\Notification;

use Mcp\Schema\JsonRpc\Notification;

/**
 * This notification is sent from the client to the server after initialization has finished.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class InitializedNotification extends Notification
{
    public static function getMethod(): string
    {
        return 'notifications/initialized';
    }

    public static function fromParams(?array $params): self
    {
        return new self();
    }

    protected function getParams(): ?array
    {
        return null;
    }
}
