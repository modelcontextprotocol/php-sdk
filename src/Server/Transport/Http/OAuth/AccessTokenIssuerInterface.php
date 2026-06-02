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
 * Mints access tokens for the authorization server.
 *
 * The default implementation issues self-validating RS256 JWTs, but a host may
 * provide an opaque-token or third-party implementation behind this contract.
 */
interface AccessTokenIssuerInterface
{
    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $claims Additional claims to embed (e.g. from the resource owner)
     *
     * @return array{token: string, tokenId: string} The encoded access token and its unique id (jti)
     */
    public function issue(
        string $subject,
        string $audience,
        array $scopes,
        string $clientId,
        int $ttlSeconds,
        array $claims = [],
    ): array;
}
