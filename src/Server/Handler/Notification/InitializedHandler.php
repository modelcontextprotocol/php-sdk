<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Notification;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializedHandler implements MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof InitializedNotification;
    }

    public function handle(InitializedNotification|HasMethodInterface $message, SessionInterface $session): Response|Error|null
    {
        $session->set('initialized', true);

        return null;
    }
}
