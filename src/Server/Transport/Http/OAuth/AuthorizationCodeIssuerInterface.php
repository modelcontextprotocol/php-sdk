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

/**
 * Issues authorization codes once an authorization request has been validated
 * and approved.
 *
 * This is one of the two engine seams (with {@see TokenGranterInterface}) a host
 * can replace to back the authorization server with a different implementation
 * (e.g. league/oauth2-server) while keeping the SDK delivery layer.
 */
interface AuthorizationCodeIssuerInterface
{
    /**
     * @param list<string> $scopes
     *
     * @return string The authorization code to return to the client
     */
    public function issueCode(
        Client $client,
        ResourceOwner $resourceOwner,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $resource = null,
    ): string;
}
