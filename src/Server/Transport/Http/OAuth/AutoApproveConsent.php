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
 * Default {@see ConsentInterface} that approves every request for an
 * authenticated resource owner, with no consent screen.
 */
final class AutoApproveConsent implements ConsentInterface
{
    public function decide(
        Client $client,
        array $scopes,
        ResourceOwner $resourceOwner,
        ServerRequestInterface $request,
    ): ConsentDecision {
        return ConsentDecision::approve();
    }
}
