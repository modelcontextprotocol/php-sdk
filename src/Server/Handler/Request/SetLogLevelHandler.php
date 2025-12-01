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
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;

/**
 * Handler for the logging/setLevel request.
 *
 * Handles client requests to set the logging level for the server.
 * The server should send all logs at this level and higher (more severe) to the client.
 *
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class SetLogLevelHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof SetLogLevelRequest;
    }

    /**
     * @return Response<EmptyResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof SetLogLevelRequest);

        $session->set(Protocol::SESSION_LOGGING_LEVEL, $request->level->value);

        return new Response($request->getId(), new EmptyResult());
    }
}
