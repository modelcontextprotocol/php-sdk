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
use Mcp\Exception\RuntimeException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Loads JWKS key sets from explicit URI or discovered issuer metadata.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class JwksProvider implements JwksProviderInterface
{
    private const CACHE_KEY_PREFIX = 'mcp_jwks_';

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    /**
     * @param OidcDiscoveryInterface       $discovery      OIDC discovery provider (required for JWKS URI resolution when $jwksUri is not explicit)
     * @param ClientInterface|null         $httpClient     PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param CacheInterface|null          $cache          Optional PSR-16 cache
     * @param int                          $cacheTtl       JWKS cache TTL in seconds
     */
    public function __construct(
        private readonly OidcDiscoveryInterface $discovery,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJwks(string $issuer, ?string $jwksUri = null): array
    {
        $jwksUri ??= $this->resolveJwksUri($issuer);
        $cacheKey = self::CACHE_KEY_PREFIX.hash('sha256', $jwksUri);

        if (null !== $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($this->isJwksValid($cached)) {
                /* @var array<string, mixed> $cached */
                return $cached;
            }
        }

        $jwks = $this->fetchJwks($jwksUri);

        if (!$this->isJwksValid($jwks)) {
            throw new RuntimeException(\sprintf('JWKS response from %s has invalid format: expected non-empty "keys" array.', $jwksUri));
        }

        if (null !== $this->cache) {
            $this->cache->set($cacheKey, $jwks, $this->cacheTtl);
        }

        return $jwks;
    }

    private function resolveJwksUri(string $issuer): string
    {
        return $this->discovery->getJwksUri($issuer);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $jwksUri): array
    {
        $request = $this->requestFactory->createRequest('GET', $jwksUri)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw new RuntimeException(\sprintf('Failed to fetch JWKS from %s: %s', $jwksUri, $e->getMessage()), 0, $e);
        }

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('Failed to fetch JWKS from %s: HTTP %d', $jwksUri, $response->getStatusCode()));
        }

        $body = $response->getBody()->__toString();

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(\sprintf('Failed to decode JWKS: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuntimeException('Invalid JWKS format: expected JSON object.');
        }

        return $data;
    }

    private function isJwksValid(mixed $jwks): bool
    {
        if (!\is_array($jwks) || !isset($jwks['keys']) || !\is_array($jwks['keys'])) {
            return false;
        }

        $nonEmptyKeys = array_filter($jwks['keys'], static fn (mixed $key): bool => \is_array($key) && [] !== $key);

        return [] !== $nonEmptyKeys;
    }
}
