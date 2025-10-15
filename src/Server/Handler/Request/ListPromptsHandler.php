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
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Server\Session\SessionInterface;

/**
 * @implements RequestHandlerInterface<ListPromptsResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListPromptsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListPromptsRequest;
    }

    /**
     * @return Response<ListPromptsResult>
     *
     * @throws InvalidCursorException
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListPromptsRequest);

        $page = $this->registry->getPrompts($this->pageSize, $request->cursor);

        return new Response(
            $request->getId(),
            new ListPromptsResult($page->references, $page->nextCursor),
        );
    }
}
