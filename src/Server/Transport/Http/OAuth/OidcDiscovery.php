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

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Resolves an authorization server's JWKS URI from its metadata.
 *
 * Tries OAuth 2.0 Authorization Server Metadata (RFC 8414) before OpenID Connect
 * Discovery, accepts a document only when its `issuer` matches verbatim, and
 * requires https for both the issuer and the JWKS URI.
 *
 * @internal used by {@see JwtTokenValidator::fromIssuer()}
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class OidcDiscovery
{
    private const CACHE_KEY_PREFIX = 'mcp_oidc_jwks_uri_';

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private readonly int $cacheTtl = 3600,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    /**
     * @throws InvalidArgumentException if the issuer is not a secure absolute URL
     * @throws RuntimeException         if no valid metadata document is found
     */
    public function getJwksUri(string $issuer): string
    {
        $item = $this->cache->getItem(self::CACHE_KEY_PREFIX.hash('sha256', $issuer));
        if ($item->isHit() && \is_string($cached = $item->get())) {
            return $cached;
        }

        $jwksUri = $this->discover($issuer);

        $this->cache->save($item->set($jwksUri)->expiresAfter($this->cacheTtl));

        return $jwksUri;
    }

    private function discover(string $issuer): string
    {
        // The trailing slash is dropped to build discovery URLs (RFC 8414 §3.1),
        // but the issuer is matched verbatim (RFC 8414 §3.3, OIDC Discovery §4.3).
        $parts = SecureUrl::parse(rtrim($issuer, '/'), 'issuer');
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';

        $urls = '' === $path
            ? [$base.'/.well-known/oauth-authorization-server', $base.'/.well-known/openid-configuration']
            : [
                $base.'/.well-known/oauth-authorization-server'.$path,
                $base.'/.well-known/openid-configuration'.$path,
                $base.$path.'/.well-known/openid-configuration',
            ];

        $lastException = null;
        foreach ($urls as $url) {
            try {
                $metadata = $this->fetchJson($url);

                if (($metadata['issuer'] ?? null) !== $issuer) {
                    throw new RuntimeException(\sprintf('Metadata at %s does not belong to issuer %s.', $url, $issuer));
                }

                $jwksUri = $metadata['jwks_uri'] ?? null;
                SecureUrl::parse($jwksUri, 'jwks_uri');
                \assert(\is_string($jwksUri));

                return $jwksUri;
            } catch (RuntimeException|InvalidArgumentException $e) {
                $lastException = $e;
            }
        }

        throw new RuntimeException(\sprintf('Failed to discover authorization server metadata for issuer: %s', $issuer), 0, $lastException);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(\sprintf('HTTP request to %s failed: %s', $url, $e->getMessage()), 0, $e);
        }

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('HTTP request to %s failed with status %d', $url, $response->getStatusCode()));
        }

        try {
            $data = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(\sprintf('Failed to decode JSON from %s: %s', $url, $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuntimeException(\sprintf('Expected JSON object from %s, got %s', $url, \gettype($data)));
        }

        return $data;
    }
}
