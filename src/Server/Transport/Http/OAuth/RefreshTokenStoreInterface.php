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
 * Stores refresh tokens. Tokens are rotated: {@see self::consume()} returns the
 * token once and atomically deletes it, and the granter issues a fresh one.
 */
interface RefreshTokenStoreInterface
{
    public function store(string $token, RefreshToken $refreshToken): void;

    /**
     * Atomically fetches and deletes the token. Returns null if it is unknown.
     */
    public function consume(string $token): ?RefreshToken;
}
