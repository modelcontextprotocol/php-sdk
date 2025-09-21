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

use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool;

    /**
     * @throws ExceptionInterface When the handler encounters an error processing the request
     */
    public function handle(HasMethodInterface $message, SessionInterface $session): Response|Error|null;
}
