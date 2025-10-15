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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Session\SessionInterface;

/**
 * @template TResult
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface RequestHandlerInterface
{
    public function supports(Request $request): bool;

    /**
     * @return Response<TResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error;
}
