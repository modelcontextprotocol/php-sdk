<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Extension;

use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Tests\Unit\Server\Extension\ThingListRequest;
use Mcp\Tests\Unit\Server\Extension\ThingListResult;

/**
 * @implements RequestHandlerInterface<ThingListResult>
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class ThingListHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof ThingListRequest;
    }

    public function handle(Request $request): Response
    {
        return new Response($request->getId(), new ThingListResult(['a', 'b']));
    }
}
