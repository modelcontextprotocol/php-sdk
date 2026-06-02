<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether an authenticated resource owner grants the client the
 * requested scopes.
 *
 * The default {@see AutoApproveConsent} approves silently. Hosts that need an
 * approval screen return {@see ConsentDecision::respondWith()} until the user
 * submits, then {@see ConsentDecision::approve()}.
 */
interface ConsentInterface
{
    /**
     * @param list<string> $scopes
     */
    public function decide(
        Client $client,
        array $scopes,
        ResourceOwner $resourceOwner,
        ServerRequestInterface $request,
    ): ConsentDecision;
}
