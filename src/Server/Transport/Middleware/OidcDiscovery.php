<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Discovers OAuth 2.0 / OpenID Connect authorization server metadata.
 *
 * Supports:
 * - OAuth 2.0 Authorization Server Metadata (RFC 8414)
 * - OpenID Connect Discovery 1.0
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class OidcDiscovery
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    private const CACHE_KEY_PREFIX = 'mcp_oidc_discovery_';

    /**
     * @param ClientInterface|null $httpClient PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param CacheInterface|null $cache PSR-16 cache for metadata (optional)
     * @param int $cacheTtl Cache TTL in seconds (default: 1 hour)
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    /**
     * Discovers authorization server metadata from the issuer URL.
     *
     * Tries endpoints in priority order per RFC 8414 and OpenID Connect Discovery:
     * 1. OAuth 2.0 path insertion: /.well-known/oauth-authorization-server/{path}
     * 2. OIDC path insertion: /.well-known/openid-configuration/{path}
     * 3. OIDC path appending: {path}/.well-known/openid-configuration
     *
     * @param string $issuer The issuer URL (e.g., "https://auth.example.com/realms/mcp")
     *
     * @return array<string, mixed> The authorization server metadata
     *
     * @throws \RuntimeException If discovery fails
     */
    public function discover(string $issuer): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $issuer);

        if (null !== $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $metadata = $this->fetchMetadata($issuer);

        if (null !== $this->cache) {
            $this->cache->set($cacheKey, $metadata, $this->cacheTtl);
        }

        return $metadata;
    }

    /**
     * Gets the JWKS URI from the authorization server metadata.
     *
     * @param string $issuer The issuer URL
     *
     * @return string The JWKS URI
     *
     * @throws \RuntimeException If JWKS URI is not found in metadata
     */
    public function getJwksUri(string $issuer): string
    {
        $metadata = $this->discover($issuer);

        if (!isset($metadata['jwks_uri']) || !\is_string($metadata['jwks_uri'])) {
            throw new \RuntimeException('Authorization server metadata does not contain jwks_uri.');
        }

        return $metadata['jwks_uri'];
    }

    /**
     * Fetches JWKS (JSON Web Key Set) from the authorization server.
     *
     * @param string $issuer The issuer URL
     *
     * @return array<string, mixed> The JWKS
     *
     * @throws \RuntimeException If fetching fails
     */
    public function fetchJwks(string $issuer): array
    {
        $jwksUri = $this->getJwksUri($issuer);

        $cacheKey = self::CACHE_KEY_PREFIX . 'jwks_' . hash('sha256', $jwksUri);

        if (null !== $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $jwks = $this->fetchJson($jwksUri);

        if (null !== $this->cache) {
            $this->cache->set($cacheKey, $jwks, $this->cacheTtl);
        }

        return $jwks;
    }

    /**
     * Checks if the authorization server supports PKCE.
     *
     * @param string $issuer The issuer URL
     *
     * @return bool True if PKCE is supported (code_challenge_methods_supported includes S256)
     */
    public function supportsPkce(string $issuer): bool
    {
        $metadata = $this->discover($issuer);

        if (!isset($metadata['code_challenge_methods_supported']) || !\is_array($metadata['code_challenge_methods_supported'])) {
            return false;
        }

        return \in_array('S256', $metadata['code_challenge_methods_supported'], true);
    }

    /**
     * Gets the token endpoint from the authorization server metadata.
     *
     * @param string $issuer The issuer URL
     *
     * @return string The token endpoint URL
     *
     * @throws \RuntimeException If token endpoint is not found
     */
    public function getTokenEndpoint(string $issuer): string
    {
        $metadata = $this->discover($issuer);

        if (!isset($metadata['token_endpoint']) || !\is_string($metadata['token_endpoint'])) {
            throw new \RuntimeException('Authorization server metadata does not contain token_endpoint.');
        }

        return $metadata['token_endpoint'];
    }

    /**
     * Gets the authorization endpoint from the authorization server metadata.
     *
     * @param string $issuer The issuer URL
     *
     * @return string The authorization endpoint URL
     *
     * @throws \RuntimeException If authorization endpoint is not found
     */
    public function getAuthorizationEndpoint(string $issuer): string
    {
        $metadata = $this->discover($issuer);

        if (!isset($metadata['authorization_endpoint']) || !\is_string($metadata['authorization_endpoint'])) {
            throw new \RuntimeException('Authorization server metadata does not contain authorization_endpoint.');
        }

        return $metadata['authorization_endpoint'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMetadata(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');
        $parsed = parse_url($issuer);

        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \RuntimeException(sprintf('Invalid issuer URL: %s', $issuer));
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        $baseUrl = $scheme . '://' . $host . $port;

        // Build discovery URLs in priority order per RFC 8414 Section 3.1
        $discoveryUrls = [];

        if ('' !== $path && '/' !== $path) {
            // For issuer URLs with path components
            // 1. OAuth 2.0 path insertion
            $discoveryUrls[] = $baseUrl . '/.well-known/oauth-authorization-server' . $path;
            // 2. OIDC path insertion
            $discoveryUrls[] = $baseUrl . '/.well-known/openid-configuration' . $path;
            // 3. OIDC path appending
            $discoveryUrls[] = $issuer . '/.well-known/openid-configuration';
        } else {
            // For issuer URLs without path components
            $discoveryUrls[] = $baseUrl . '/.well-known/oauth-authorization-server';
            $discoveryUrls[] = $baseUrl . '/.well-known/openid-configuration';
        }

        $lastException = null;

        foreach ($discoveryUrls as $url) {
            try {
                $metadata = $this->fetchJson($url);

                // Validate issuer claim matches
                if (isset($metadata['issuer']) && $metadata['issuer'] !== $issuer) {
                    continue;
                }

                return $metadata;
            } catch (\RuntimeException $e) {
                $lastException = $e;
                continue;
            }
        }

        throw new \RuntimeException(
            sprintf('Failed to discover authorization server metadata for issuer: %s', $issuer),
            0,
            $lastException
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf(
                'HTTP request to %s failed with status %d',
                $url,
                $response->getStatusCode()
            ));
        }

        $body = (string)$response->getBody();

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode JSON from %s: %s', $url, $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('Expected JSON object from %s, got %s', $url, \gettype($data)));
        }

        return $data;
    }
}
