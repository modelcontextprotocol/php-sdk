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
 * Persists and retrieves OAuth 2.0 client registrations.
 *
 * Hosts implement this against their own storage (database, cache, etc.).
 */
interface ClientRepositoryInterface
{
    public function find(string $clientId): ?Client;

    public function save(Client $client): void;
}
