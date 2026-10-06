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

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\CachedKeySet;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Server\Authorization\AccessToken;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Validates JWT access tokens issued by one authorization server.
 *
 * Checks, in order: the `alg` header against the allowlist, the `typ` header when
 * required, the signature and time claims, the issuer, and the audience. The
 * audience must name this MCP server — accepting tokens minted for another
 * resource is the token passthrough the MCP specification forbids.
 *
 * Requires: firebase/php-jwt
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class JwtTokenValidator implements AuthorizationTokenValidatorInterface
{
    /** @var list<string> */
    private readonly array $audiences;

    /**
     * @param string                                       $issuer     expected `iss` claim
     * @param string|list<string>                          $audience   accepted `aud` values, typically the resource identifier of this MCP server
     * @param \ArrayAccess<string, Key>|array<string, Key> $keys       verification keys by key id, e.g. a {@see CachedKeySet}
     * @param list<string>                                 $algorithms accepted `alg` header values
     * @param string                                       $scopeClaim claim holding the granted scopes
     * @param string|null                                  $tokenType  required `typ` header, e.g. `at+jwt` (RFC 9068); null skips the check
     * @param int                                          $leeway     tolerated clock skew in seconds
     */
    public function __construct(
        private readonly string $issuer,
        string|array $audience,
        private readonly \ArrayAccess|array $keys,
        private readonly array $algorithms = ['RS256'],
        private readonly string $scopeClaim = 'scope',
        private readonly ?string $tokenType = null,
        private readonly int $leeway = 0,
    ) {
        if (!class_exists(JWT::class)) {
            throw new RuntimeException('For using the JwtTokenValidator, the firebase/php-jwt package is required. Try running "composer require firebase/php-jwt".');
        }

        $this->audiences = \is_array($audience) ? $audience : [$audience];
        if ([] === $this->audiences || [] === $algorithms) {
            throw new InvalidArgumentException('The JwtTokenValidator requires at least one audience and one algorithm.');
        }
    }

    /**
     * Builds a validator that discovers the issuer's JWKS URI and caches its keys.
     *
     * Keys are refetched, rate limited, when a token names an unknown key id, so
     * key rotation does not lock clients out until the cache expires. Issuer and
     * JWKS URI must use https, unless they point to a loopback host.
     *
     * @param string|list<string> $audience
     * @param list<string>        $algorithms
     */
    public static function fromIssuer(
        string $issuer,
        string|array $audience,
        CacheItemPoolInterface $cache,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        array $algorithms = ['RS256'],
        string $scopeClaim = 'scope',
        ?string $tokenType = null,
        int $leeway = 0,
    ): self {
        $httpClient ??= Psr18ClientDiscovery::find();
        $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();

        $jwksUri = (new OidcDiscovery($cache, $httpClient, $requestFactory))->getJwksUri($issuer);
        $keys = new CachedKeySet($jwksUri, $httpClient, $requestFactory, $cache, 3600, true, $algorithms[0] ?? null);

        return new self($issuer, $audience, $keys, $algorithms, $scopeClaim, $tokenType, $leeway);
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        $header = $this->decodeHeader($accessToken);
        if (null === $header) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is malformed.');
        }

        if (!\in_array($header['alg'] ?? null, $this->algorithms, true)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token algorithm is not accepted.');
        }

        if (null !== $this->tokenType && !$this->isExpectedType($header['typ'] ?? null)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token type is not accepted.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->leeway;

        try {
            /** @var array<string, mixed> $claims */
            $claims = (array) JWT::decode($accessToken, $this->keys);
        } catch (ExpiredException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token has expired.');
        } catch (BeforeValidException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not yet valid.');
        } catch (SignatureInvalidException|\InvalidArgumentException|\UnexpectedValueException|\DomainException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token validation failed.');
        } catch (\OutOfBoundsException|ClientExceptionInterface) {
            // CachedKeySet: unknown key id after refetching, or the JWKS endpoint is unreachable.
            return AuthorizationResult::unauthorized('invalid_token', 'Token signing key could not be resolved.');
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token issuer mismatch.');
        }

        $audiences = $claims['aud'] ?? [];
        if ([] === array_intersect($this->audiences, \is_array($audiences) ? $audiences : [$audiences])) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token audience mismatch.');
        }

        return AuthorizationResult::allow(new AccessToken($this->extractScopes($claims), $claims));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeHeader(string $token): ?array
    {
        $segments = explode('.', $token);
        if (3 !== \count($segments)) {
            return null;
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
        } catch (\Throwable) {
            return null;
        }

        return $header instanceof \stdClass ? (array) $header : null;
    }

    private function isExpectedType(mixed $type): bool
    {
        if (!\is_string($type) || null === $this->tokenType) {
            return false;
        }

        // RFC 9068 §2.1: "application/" may be omitted, media types are case-insensitive.
        $normalize = static fn (string $value): string => preg_replace('#^application/#', '', strtolower($value)) ?? '';

        return $normalize($type) === $normalize($this->tokenType);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function extractScopes(array $claims): array
    {
        $value = $claims[$this->scopeClaim] ?? null;

        if (\is_string($value)) {
            $value = explode(' ', $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $scope): bool => \is_string($scope) && '' !== $scope));
    }
}
