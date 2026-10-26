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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\DiscoverRequest;
use Mcp\Schema\Result\DiscoverResult;
use Mcp\Server\Configuration;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers `server/discover` with the modern revisions this server serves, its
 * capabilities and its instructions.
 *
 * Registered by {@see \Mcp\Server\Builder::buildStateless()} after every other
 * handler, so a handler added through
 * {@see \Mcp\Server\Builder::addRequestHandler()} that supports
 * {@see DiscoverRequest} answers instead. The handshake era never parses a
 * `server/discover` request, so neither this handler nor a replacement is
 * reachable there.
 *
 * @implements RequestHandlerInterface<DiscoverResult>
 */
final class DiscoverHandler implements RequestHandlerInterface
{
    /**
     * @param list<ProtocolVersion> $supportedVersions the modern revisions the dispatcher answers for
     */
    public function __construct(
        public readonly Configuration $configuration,
        public readonly array $supportedVersions,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof DiscoverRequest;
    }

    /**
     * @return Response<DiscoverResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof DiscoverRequest);

        return new Response(
            $request->getId(),
            new DiscoverResult(
                $this->supportedVersions,
                $this->configuration->capabilities,
                $this->configuration->instructions,
            ),
        );
    }
}
