<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ChangeListener
{
    public function __construct(
        private readonly Protocol $protocol,
    ) {
    }

    public function onPromptListChange(): void
    {
        $this->protocol->sendNotification(new PromptListChangedNotification());
    }

    public function onResourceListChange(): void
    {
        $this->protocol->sendNotification(new ResourceListChangedNotification());
    }

    public function onToolListChange(): void
    {
        $this->protocol->sendNotification(new ToolListChangedNotification());
    }
}
