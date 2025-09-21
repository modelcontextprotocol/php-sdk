<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PingHandler implements MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof PingRequest;
    }

    public function handle(PingRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof PingRequest);

        return new Response($message->getId(), new EmptyResult());
    }
}
