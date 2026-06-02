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

use Mcp\Exception\OAuthException;

/**
 * Processes token endpoint requests (RFC 6749 Section 4 / 6).
 *
 * Implementations perform client authentication, grant-specific validation and
 * token minting from the parsed request parameters, so the delivery layer
 * (PSR-15 middleware or a host controller) only has to parse and serialize.
 */
interface TokenGranterInterface
{
    /**
     * @param array<string, mixed> $params The parsed application/x-www-form-urlencoded body,
     *                                     with client_id/client_secret normalized from any Basic auth header
     *
     * @throws OAuthException On any protocol error (mapped to an RFC 6749 §5.2 response)
     */
    public function grant(string $grantType, array $params): TokenResponse;
}
