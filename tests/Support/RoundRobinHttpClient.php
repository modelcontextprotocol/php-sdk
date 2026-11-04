<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Support;

use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends each request to the next instance, like a load balancer in front of a deployment.
 */
final class RoundRobinHttpClient implements ClientInterface
{
    private ClientInterface $client;
    private int $sent = 0;

    /**
     * @param non-empty-list<string> $endpoints
     */
    public function __construct(private readonly array $endpoints)
    {
        $this->client = Psr18ClientDiscovery::find();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $endpoint = parse_url($this->endpoints[$this->sent++ % \count($this->endpoints)]);
        $uri = $request->getUri()->withHost((string) ($endpoint['host'] ?? ''))->withPort($endpoint['port'] ?? null);

        return $this->client->sendRequest($request->withUri($uri));
    }
}
