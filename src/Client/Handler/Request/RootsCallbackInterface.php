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

use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;

/**
 * Contract for callbacks used by ListRootsRequestHandler.
 *
 * Implementations return the list of filesystem roots the client exposes when
 * requested by the server.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface RootsCallbackInterface
{
    public function __invoke(ListRootsRequest $request): ListRootsResult;
}
