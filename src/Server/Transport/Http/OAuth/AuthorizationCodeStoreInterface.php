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
 * Stores authorization codes for the authorization code grant.
 *
 * Codes MUST be single-use: {@see self::consume()} returns the code once and
 * atomically deletes it, so a replayed code is rejected.
 */
interface AuthorizationCodeStoreInterface
{
    public function store(string $code, AuthorizationCode $authorizationCode): void;

    /**
     * Atomically fetches and deletes the code. Returns null if it is unknown.
     */
    public function consume(string $code): ?AuthorizationCode;
}
