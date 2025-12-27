<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\CustomMethodHandlers;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/** @implements RequestHandlerInterface<ListToolsResult> */
class ListToolsRequestHandler implements RequestHandlerInterface
{
    /**
     * @param array<string, Tool> $toolDefinitions
     */
    public function __construct(private array $toolDefinitions)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /**
     * @return Response<ListToolsResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);

        return new Response($request->getId(), new ListToolsResult(array_values($this->toolDefinitions), null));
    }
}
