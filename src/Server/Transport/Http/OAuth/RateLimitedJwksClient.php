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

use Mcp\Exception\JwksRefreshRateLimitException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Reports a suppressed JWKS refresh as unavailable instead of a missing key.
 *
 * @internal
 *
 * @author Erik Thiart
 */
final class RateLimitedJwksClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $item = $this->cache->getItem(hash('sha256', 'mcp_jwks_rate_limit_'.(string) $request->getUri()));

        /** @var array{attempts: int, expiresAt: int} $window */
        $window = $item->isHit() ? $item->get() : ['attempts' => 0, 'expiresAt' => time() + 60];

        if (++$window['attempts'] > 10) {
            throw new JwksRefreshRateLimitException(max(1, $window['expiresAt'] - time()));
        }

        $this->cache->save($item->set($window)->expiresAt(new \DateTimeImmutable('@'.$window['expiresAt'])));

        return $this->client->sendRequest($request);
    }
}
