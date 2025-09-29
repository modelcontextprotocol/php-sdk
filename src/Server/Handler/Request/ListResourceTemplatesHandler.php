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
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ListResourceTemplatesHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ListResourceTemplatesRequest;
    }

    /**
     * @throws InvalidCursorException
     */
    public function handle(ListResourceTemplatesRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof ListResourceTemplatesRequest);

        $page = $this->registry->getResourceTemplates($this->pageSize, $message->cursor);

        return new Response(
            $message->getId(),
            new ListResourceTemplatesResult($page->references, $page->nextCursor),
        );
    }
}
