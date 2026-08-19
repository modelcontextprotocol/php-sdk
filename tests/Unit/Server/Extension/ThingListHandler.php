<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Extension;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @implements RequestHandlerInterface<ThingListResult>
 */
final class ThingListHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof ThingListRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        return new Response($request->getId(), new ThingListResult(['a', 'b']));
    }
}
