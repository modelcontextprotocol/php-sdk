<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Middleware;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Wraps a request received by the server and the response it produces.
 *
 * @author OpenAI
 */
interface RequestMiddlewareInterface
{
    /**
     * @param RequestHandlerInterface<ResultInterface|array<string, mixed>> $next
     *
     * @return Response<ResultInterface|array<string, mixed>>|Error
     */
    public function processRequest(Request $request, SessionInterface $session, RequestHandlerInterface $next): Response|Error;
}
