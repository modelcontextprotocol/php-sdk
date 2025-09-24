<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListToolsHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ListToolsRequest;
    }

    /**
     * @throws InvalidCursorException When the cursor is invalid
     */
    public function handle(ListToolsRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof ListToolsRequest);

        $page = $this->registry->getTools($this->pageSize, $message->cursor);

        return new Response(
            $message->getId(),
            new ListToolsResult($page->references, $page->nextCursor),
        );
    }
}
