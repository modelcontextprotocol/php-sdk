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

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListResourcesHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListResourcesRequest;
    }

    /**
     * @throws InvalidCursorException
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListResourcesRequest);

        $page = $this->registry->getResources($this->pageSize, $request->cursor);

        return new Response(
            $request->getId(),
            new ListResourcesResult($page->references, $page->nextCursor),
        );
    }
}
