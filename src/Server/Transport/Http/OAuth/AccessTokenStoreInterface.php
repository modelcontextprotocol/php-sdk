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
 * Optional registry of issued access tokens enabling server-side revocation.
 *
 * Self-contained JWT access tokens are valid until they expire; wiring an
 * implementation of this interface into the token validation path lets a host
 * revoke a token (by its "jti") before expiry, at parity with stateful
 * authorization servers.
 */
interface AccessTokenStoreInterface
{
    public function record(string $tokenId, string $clientId, string $userId, \DateTimeImmutable $expiresAt): void;

    public function isRevoked(string $tokenId): bool;

    public function revoke(string $tokenId): void;
}
