<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Mcp\Exception\InvalidArgumentException;

/**
 * An OAuth 2.1 token response: the access token plus everything needed to keep using it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AccessToken implements \JsonSerializable
{
    /**
     * Seconds of headroom before the nominal expiry at which the token is treated as stale.
     *
     * Without it a token that expires while the request is in flight would be sent anyway,
     * costing a round trip and a challenge to learn what was already known.
     */
    private const EXPIRY_LEEWAY_SECONDS = 30;

    /**
     * @param string   $accessToken  the token to present to the resource server
     * @param string   $tokenType    the token type, "Bearer" for everything MCP defines
     * @param ?int     $expiresAt    unix timestamp the token expires at, null when the server did not say
     * @param ?string  $refreshToken the refresh token, when the grant produced one
     * @param string[] $scopes       the scopes the authorization server actually granted
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'Bearer',
        public readonly ?int $expiresAt = null,
        public readonly ?string $refreshToken = null,
        public readonly array $scopes = [],
    ) {
        if ('' === trim($accessToken)) {
            throw new InvalidArgumentException('The access token must not be empty.');
        }
    }

    /**
     * Build a token from a parsed token endpoint response (RFC 6749 section 5.1).
     *
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response, ?int $now = null): self
    {
        if (!isset($response['access_token']) || !\is_string($response['access_token'])) {
            throw new InvalidArgumentException('The token response is missing a string "access_token".');
        }

        $expiresIn = $response['expires_in'] ?? null;

        return new self(
            $response['access_token'],
            \is_string($response['token_type'] ?? null) ? $response['token_type'] : 'Bearer',
            is_numeric($expiresIn) ? ($now ?? time()) + (int) $expiresIn : null,
            \is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : null,
            \is_string($response['scope'] ?? null) ? self::splitScopes($response['scope']) : [],
        );
    }

    public function isExpired(?int $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return ($now ?? time()) + self::EXPIRY_LEEWAY_SECONDS >= $this->expiresAt;
    }

    /**
     * Whether every one of the given scopes was granted.
     *
     * Authorization servers may narrow what was asked for, so "we requested it" and
     * "we hold it" are different questions.
     *
     * @param string[] $scopes
     */
    public function covers(array $scopes): bool
    {
        if ([] === $this->scopes) {
            // The server did not report a scope, which per RFC 6749 means it granted
            // exactly what was requested. Nothing can be ruled out from here.
            return true;
        }

        return [] === array_diff($scopes, $this->scopes);
    }

    /**
     * Record what was granted when the authorization server did not say.
     *
     * RFC 6749 section 5.1 makes the scope field optional precisely when the grant
     * matches the request, so the requested set is the granted set -- writing it down
     * is what lets a later challenge be told apart from a token that is simply stale.
     *
     * @param string[] $scopes
     */
    public function withGrantedScopes(array $scopes): self
    {
        if ([] !== $this->scopes || [] === $scopes) {
            return $this;
        }

        return new self($this->accessToken, $this->tokenType, $this->expiresAt, $this->refreshToken, $scopes);
    }

    public function getAuthorizationHeader(): string
    {
        return $this->tokenType.' '.$this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt,
            'refresh_token' => $this->refreshToken,
            'scopes' => $this->scopes,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['access_token'] ?? null) ? $data['access_token'] : '',
            \is_string($data['token_type'] ?? null) ? $data['token_type'] : 'Bearer',
            isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : null,
            \is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            array_values(array_filter((array) ($data['scopes'] ?? []), 'is_string')),
        );
    }

    /**
     * @return string[]
     */
    private static function splitScopes(string $scope): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }
}
