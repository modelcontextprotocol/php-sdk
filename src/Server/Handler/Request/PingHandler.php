<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Request;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PingHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof PingRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof PingRequest);

        return new Response($request->getId(), new EmptyResult());
    }
}
