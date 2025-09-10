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

use Mcp\Capability\Registry;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server\MethodHandlerInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListResourcesHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ListResourcesRequest;
    }

    /**
     * @throws InvalidCursorException
     */
    public function handle(ListResourcesRequest|HasMethodInterface $message): Response
    {
        \assert($message instanceof ListResourcesRequest);

        $allResources = $this->registry->getResources();

        $resources = $this->registry->getResources($this->pageSize, $message->cursor);

        $nextCursor = $this->registry->calculateNextCursor(
            $allResources,
            $message->cursor,
            \count($resources)
        );

        return new Response(
            $message->getId(),
            new ListResourcesResult($resources, $nextCursor),
        );
    }
}