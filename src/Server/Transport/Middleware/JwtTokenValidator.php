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

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Validates JWT access tokens using JWKS from an OAuth 2.0 / OpenID Connect provider.
 *
 * This validator:
 * - Fetches JWKS from the authorization server (auto-discovered or explicit)
 * - Caches JWKS for performance
 * - Validates signature, audience, issuer, and expiration
 * - Extracts scopes and claims as request attributes
 *
 * Requires: firebase/php-jwt
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class JwtTokenValidator implements AuthorizationTokenValidatorInterface
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private ?OidcDiscovery $discovery = null;

    private const CACHE_KEY_PREFIX = 'mcp_jwt_jwks_';

    /**
     * @param string|list<string> $issuer Expected token issuer(s) (e.g., "https://auth.example.com/realms/mcp")  For Microsoft Entra ID, you may need to provide both v1.0 and v2.0 issuers
     * @param string|list<string> $audience Expected audience(s) for the token
     * @param string|null $jwksUri Explicit JWKS URI (auto-discovered from first issuer if null)
     * @param ClientInterface|null $httpClient PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param CacheInterface|null $cache PSR-16 cache for JWKS (optional)
     * @param int $cacheTtl Cache TTL in seconds (default: 1 hour)
     * @param list<string> $algorithms Allowed JWT algorithms (default: RS256, RS384, RS512)
     * @param string $scopeClaim Claim name for scopes (default: "scope")
     */
    public function __construct(
        private readonly string|array $issuer,
        private readonly string|array $audience,
        private readonly ?string $jwksUri = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600,
        private readonly array $algorithms = ['RS256', 'RS384', 'RS512'],
        private readonly string $scopeClaim = 'scope',
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult
    {
        // Decode header to see key ID
        $parts = explode('.', $accessToken);
        $header = null;
        if (count($parts) >= 2) {
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        }

        // Microsoft Graph tokens have 'nonce' in header and cannot be verified externally
        // These are opaque tokens meant only for Microsoft Graph API
        if (isset($header['nonce'])) {
            return $this->validateGraphToken($accessToken, $parts);
        }

        try {
            $keys = $this->getJwks();
            $decoded = JWT::decode($accessToken, $keys);
            /** @var array<string, mixed> $claims */
            $claims = (array)$decoded;

            // Validate issuer
            if (!$this->validateIssuer($claims)) {
                return AuthorizationResult::unauthorized(
                    'invalid_token',
                    'Token issuer mismatch.'
                );
            }

            // Validate audience
            if (!$this->validateAudience($claims)) {
                return AuthorizationResult::unauthorized(
                    'invalid_token',
                    'Token audience mismatch.'
                );
            }

            // Extract scopes
            $scopes = $this->extractScopes($claims);

            // Build attributes to attach to request
            $attributes = [
                'oauth.claims' => $claims,
                'oauth.scopes' => $scopes,
            ];

            // Add common claims as individual attributes
            if (isset($claims['sub'])) {
                $attributes['oauth.subject'] = $claims['sub'];
            }

            if (isset($claims['client_id'])) {
                $attributes['oauth.client_id'] = $claims['client_id'];
            }

            // Add azp (authorized party) for OIDC tokens
            if (isset($claims['azp'])) {
                $attributes['oauth.authorized_party'] = $claims['azp'];
            }

            return AuthorizationResult::allow($attributes);
        } catch (\Firebase\JWT\ExpiredException $e) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token has expired.'
            );
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token signature verification failed.'
            );
        } catch (\Firebase\JWT\BeforeValidException $e) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token is not yet valid.'
            );
        } catch (\UnexpectedValueException|\DomainException $e) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token validation failed: ' . $e->getMessage()
            );
        } catch (\Throwable $e) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token validation error.'
            );
        }
    }

    /**
     * Validates Microsoft Graph tokens that cannot be signature-verified externally.
     *
     * Microsoft Graph access tokens contain a 'nonce' in the header and use a special
     * format where the signature cannot be verified by third parties. These tokens are
     * meant only for Microsoft Graph API consumption.
     *
     * This method performs claim-based validation without signature verification.
     *
     * @param string $accessToken The JWT access token
     * @param array<string> $parts Token parts (header, payload, signature)
     */
    private function validateGraphToken(string $accessToken, array $parts): AuthorizationResult
    {
        if (count($parts) < 2) {
            return AuthorizationResult::unauthorized('invalid_token', 'Invalid token format.');
        }

        try {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (null === $payload) {
                return AuthorizationResult::unauthorized('invalid_token', 'Invalid token payload.');
            }

            // Validate expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return AuthorizationResult::unauthorized('invalid_token', 'Token has expired.');
            }

            // Validate not before
            if (isset($payload['nbf']) && $payload['nbf'] > time() + 60) {
                return AuthorizationResult::unauthorized('invalid_token', 'Token is not yet valid.');
            }

            // For Graph tokens, we accept them if they came from Microsoft
            // The issuer should be Microsoft's STS
            $issuer = $payload['iss'] ?? '';
            if (!str_contains($issuer, 'sts.windows.net') && !str_contains($issuer, 'login.microsoftonline.com')) {
                return AuthorizationResult::unauthorized('invalid_token', 'Invalid token issuer for Graph token.');
            }

            // Extract scopes
            $scopes = $this->extractScopes($payload);

            // Build attributes
            $attributes = [
                'oauth.claims' => $payload,
                'oauth.scopes' => $scopes,
                'oauth.graph_token' => true, // Mark as Graph token
            ];

            if (isset($payload['sub'])) {
                $attributes['oauth.subject'] = $payload['sub'];
            }

            if (isset($payload['oid'])) {
                $attributes['oauth.object_id'] = $payload['oid'];
            }

            if (isset($payload['name'])) {
                $attributes['oauth.name'] = $payload['name'];
            }

            return AuthorizationResult::allow($attributes);
        } catch (\Throwable $e) {
            return AuthorizationResult::unauthorized('invalid_token', 'Graph token validation failed.');
        }
    }

    /**
     * Validates a token has the required scopes.
     *
     * Use this after validation to check specific scope requirements.
     *
     * @param AuthorizationResult $result The result from validate()
     * @param list<string> $requiredScopes Scopes required for this operation
     *
     * @return AuthorizationResult The original result if scopes are sufficient, forbidden otherwise
     */
    public function requireScopes(AuthorizationResult $result, array $requiredScopes): AuthorizationResult
    {
        if (!$result->isAllowed()) {
            return $result;
        }

        $tokenScopes = $result->getAttributes()['oauth.scopes'] ?? [];

        if (!\is_array($tokenScopes)) {
            $tokenScopes = [];
        }

        foreach ($requiredScopes as $required) {
            if (!\in_array($required, $tokenScopes, true)) {
                return AuthorizationResult::forbidden(
                    'insufficient_scope',
                    sprintf('Required scope: %s', $required),
                    $requiredScopes
                );
            }
        }

        return $result;
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function getJwks(): array
    {
        $jwksUri = $this->resolveJwksUri();
        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $jwksUri);

        $jwksData = null;

        if (null !== $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if (\is_array($cached)) {
                /** @var array<string, mixed> $cached */
                $jwksData = $cached;
            }
        }

        if (null === $jwksData) {
            $jwksData = $this->fetchJwks($jwksUri);

            if (null !== $this->cache) {
                $this->cache->set($cacheKey, $jwksData, $this->cacheTtl);
            }
        }

        /** @var array<string, \Firebase\JWT\Key> */
        return JWK::parseKeySet($jwksData, $this->algorithms[0]);
    }

    private function resolveJwksUri(): string
    {
        if (null !== $this->jwksUri) {
            return $this->jwksUri;
        }

        // Auto-discover from first issuer
        if (null === $this->discovery) {
            $this->discovery = new OidcDiscovery(
                $this->httpClient,
                $this->requestFactory,
                $this->cache,
                $this->cacheTtl
            );
        }

        $issuers = \is_array($this->issuer) ? $this->issuer : [$this->issuer];

        return $this->discovery->getJwksUri($issuers[0]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateIssuer(array $claims): bool
    {
        if (!isset($claims['iss'])) {
            return false;
        }

        $tokenIssuer = $claims['iss'];
        $expectedIssuers = \is_array($this->issuer) ? $this->issuer : [$this->issuer];

        return \in_array($tokenIssuer, $expectedIssuers, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $jwksUri): array
    {
        $request = $this->requestFactory->createRequest('GET', $jwksUri)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf(
                'Failed to fetch JWKS from %s: HTTP %d',
                $jwksUri,
                $response->getStatusCode()
            ));
        }

        $body = (string)$response->getBody();

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode JWKS: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($data) || !isset($data['keys'])) {
            throw new \RuntimeException('Invalid JWKS format: missing "keys" array.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateAudience(array $claims): bool
    {
        if (!isset($claims['aud'])) {
            return false;
        }

        $tokenAudiences = \is_array($claims['aud']) ? $claims['aud'] : [$claims['aud']];
        $expectedAudiences = \is_array($this->audience) ? $this->audience : [$this->audience];

        foreach ($expectedAudiences as $expected) {
            if (\in_array($expected, $tokenAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function extractScopes(array $claims): array
    {
        if (!isset($claims[$this->scopeClaim])) {
            return [];
        }

        $scopeValue = $claims[$this->scopeClaim];

        if (\is_array($scopeValue)) {
            return array_values(array_filter($scopeValue, 'is_string'));
        }

        if (\is_string($scopeValue)) {
            return array_values(array_filter(explode(' ', $scopeValue)));
        }

        return [];
    }
}
