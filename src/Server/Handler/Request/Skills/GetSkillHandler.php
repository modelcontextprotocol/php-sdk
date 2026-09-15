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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Skills\GetSkillRequest;
use Mcp\Schema\Extension\Skills\GetSkillResult;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Skill\SkillRegistry;

/**
 * @implements RequestHandlerInterface<GetSkillResult>
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GetSkillHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SkillRegistry $registry,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetSkillRequest;
    }

    /**
     * @throws InvalidArgumentException if the URI does not identify a skill the server serves;
     *                                  rendered as -32602 (Invalid params), per the extension's
     *                                  error handling
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof GetSkillRequest);

        $skill = $this->registry->get($request->uri);

        if (null === $skill) {
            throw new InvalidArgumentException(\sprintf('No skill is served at %s', $request->uri));
        }

        return new Response($request->getId(), new GetSkillResult($skill));
    }
}
