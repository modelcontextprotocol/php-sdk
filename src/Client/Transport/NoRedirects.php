<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Turns off redirects on a `symfony/http-client` client, the only PSR-18 client that can be told to.
 *
 * Whatever a client follows gets the headers and body of the request, credentials included.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class NoRedirects
{
    public static function discover(): ClientInterface
    {
        $client = Psr18ClientDiscovery::find();

        if (!$client instanceof Psr18Client) {
            return $client;
        }

        // Psr18Client has no withOptions() before symfony/http-client 6.2.
        return \is_callable([$client, 'withOptions'])
            ? $client->withOptions(['max_redirects' => 0])
            : new Psr18Client(HttpClient::create(['max_redirects' => 0]));
    }

    public static function apply(ClientInterface $client): ClientInterface
    {
        return $client instanceof Psr18Client && \is_callable([$client, 'withOptions'])
            ? $client->withOptions(['max_redirects' => 0])
            : $client;
    }
}
