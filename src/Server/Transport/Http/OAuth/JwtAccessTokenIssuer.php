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

use Firebase\JWT\JWT;
use Mcp\Exception\RuntimeException;
use Psr\Clock\ClockInterface;

/**
 * Issues self-validating RS256 JWT access tokens.
 *
 * Tokens issued here are designed to validate, unchanged, through
 * {@see JwtTokenValidator} configured with this server as issuer and the
 * matching JWKS. The claim shape is configurable so it can also match the shape
 * of another authorization server (e.g. league/oauth2-server, which uses an
 * array "scopes" claim and an "aud" set to the client id) for a phased
 * migration.
 *
 * Requires: firebase/php-jwt
 */
final class JwtAccessTokenIssuer implements AccessTokenIssuerInterface
{
    private const ALGORITHM = 'RS256';

    public function __construct(
        private readonly SigningKeyInterface $signingKey,
        private readonly string $issuer,
        private readonly ?ClockInterface $clock = null,
        private readonly string $scopeClaim = 'scope',
        private readonly bool $scopesAsArray = false,
        private readonly bool $includeNotBefore = false,
    ) {
        if (!class_exists(JWT::class)) {
            throw new RuntimeException('For using the JwtAccessTokenIssuer, the firebase/php-jwt package is required. Try running "composer require firebase/php-jwt".');
        }
    }

    public function issue(
        string $subject,
        string $audience,
        array $scopes,
        string $clientId,
        int $ttlSeconds,
        array $claims = [],
    ): array {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        $tokenId = bin2hex(random_bytes(16));

        $payload = $claims;
        $payload['iss'] = $this->issuer;
        $payload['sub'] = $subject;
        $payload['aud'] = $audience;
        $payload['client_id'] = $clientId;
        $payload['jti'] = $tokenId;
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttlSeconds;
        $payload[$this->scopeClaim] = $this->scopesAsArray ? array_values($scopes) : implode(' ', $scopes);

        if ($this->includeNotBefore) {
            $payload['nbf'] = $now;
        }

        $token = JWT::encode($payload, $this->signingKey->getPrivateKeyPem(), self::ALGORITHM, $this->signingKey->getKeyId());

        return ['token' => $token, 'tokenId' => $tokenId];
    }
}
