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
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListPromptsHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ListPromptsRequest;
    }

    public function handle(ListPromptsRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof ListPromptsRequest);

        $cursor = null;
        $prompts = $this->registry->getPrompts($this->pageSize, $message->cursor);
        $nextCursor = (null !== $cursor && \count($prompts) === $this->pageSize) ? $cursor : null;

        return new Response(
            $message->getId(),
            new ListPromptsResult($prompts, $nextCursor),
        );
    }
}
