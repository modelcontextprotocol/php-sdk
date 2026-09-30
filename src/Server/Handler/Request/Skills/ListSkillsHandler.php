<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Request\Skills;

use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Extension\Skills\ListSkillsRequest;
use Mcp\Schema\Extension\Skills\ListSkillsResult;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Skill\SkillRegistry;

/**
 * @implements RequestHandlerInterface<ListSkillsResult>
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ListSkillsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly int $pageSize = 20,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListSkillsRequest;
    }

    /**
     * @throws InvalidCursorException
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListSkillsRequest);

        $skills = $this->registry->all();

        $offset = 0;
        if (null !== $request->cursor) {
            $decoded = base64_decode($request->cursor, true);
            if (false === $decoded || !is_numeric($decoded)) {
                throw new InvalidCursorException($request->cursor);
            }

            $offset = (int) $decoded;
            if ($offset < 0 || $offset > \count($skills)) {
                throw new InvalidCursorException($request->cursor);
            }
        }

        $page = \array_slice($skills, $offset, $this->pageSize);

        $nextOffset = $offset + $this->pageSize;
        $nextCursor = $nextOffset < \count($skills) ? base64_encode((string) $nextOffset) : null;

        return new Response($request->getId(), new ListSkillsResult($page, $nextCursor));
    }
}
