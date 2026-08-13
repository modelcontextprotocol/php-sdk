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

use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Session\SessionInterface;

/**
 * Handles server/discover for MCP 2026-07-28 stateless mode.
 *
 * Replaces initialize handshake — returns capabilities and serverInfo
 * without creating a persistent session.
 *
 * @phpstan-ignore missingType.generics
 */
final class ServerDiscoverHandler implements RequestHandlerInterface
{
    public function __construct(
        public readonly ?Configuration $configuration = null,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'server/discover' === $request::getMethod();
    }

    /**
     * @return Response<InitializeResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        return new Response(
            $request->getId(),
            new InitializeResult(
                $this->configuration->capabilities ?? new ServerCapabilities(),
                $this->configuration->serverInfo ?? new Implementation(),
                $this->configuration?->instructions,
                null,
                $this->configuration?->protocolVersion,
            ),
        );
    }
}
