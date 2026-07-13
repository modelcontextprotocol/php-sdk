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
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handler for roots/list requests from the server.
 *
 * The MCP server may ask the client for the list of filesystem roots (its
 * "workspace folders"). This handler wraps a user-provided callback that returns
 * the roots the client wishes to expose.
 *
 * @implements RequestHandlerInterface<ListRootsResult>
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ListRootsRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RootsCallbackInterface $callback,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListRootsRequest;
    }

    /**
     * @return Response<ListRootsResult>|Error
     */
    public function handle(Request $request): Response|Error
    {
        \assert($request instanceof ListRootsRequest);

        try {
            $result = $this->callback->__invoke($request);

            return new Response($request->getId(), $result);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error while listing roots', ['exception' => $e]);

            return Error::forInternalError('Error while listing roots', $request->getId());
        }
    }
}
