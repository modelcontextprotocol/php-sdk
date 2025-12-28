<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Handler\Request;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;

/**
 * Interface for handling requests from the server.
 *
 * @template TResult
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface RequestHandlerInterface
{
    /**
     * Check if this handler supports the given request.
     */
    public function supports(Request $request): bool;

    /**
     * Handle the request and return a response or error.
     *
     * @return Response<TResult>|Error
     */
    public function handle(Request $request): Response|Error;
}
