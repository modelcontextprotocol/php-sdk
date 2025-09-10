<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Capability\Registry;
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

    public function handle(ListResourcesRequest|HasMethodInterface $message): Response
    {
        \assert($message instanceof ListResourcesRequest);

        $cursor = null;
        $resources = $this->registry->getResources();
        $nextCursor = (null !== $cursor && \count($resources) === $this->pageSize) ? $cursor : null;

        return new Response(
            $message->getId(),
            new ListResourcesResult($resources, $nextCursor),
        );
    }
}
