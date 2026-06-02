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
 * In-memory {@see ClientRepositoryInterface} for tests, examples and
 * single-process servers. Not suitable for multi-process deployments.
 */
final class InMemoryClientRepository implements ClientRepositoryInterface
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param list<Client> $clients
     */
    public function __construct(array $clients = [])
    {
        foreach ($clients as $client) {
            $this->save($client);
        }
    }

    public function find(string $clientId): ?Client
    {
        return $this->clients[$clientId] ?? null;
    }

    public function save(Client $client): void
    {
        $this->clients[$client->clientId] = $client;
    }
}
