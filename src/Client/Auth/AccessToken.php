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

use Mcp\Client\Exception\InvalidArgumentException;

/**
 * An OAuth 2.1 token response: the access token plus everything needed to keep using it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AccessToken implements \JsonSerializable
{
    /**
     * Seconds before the expiry at which a stored token isn't worth reusing, as it could expire in flight.
     */
    private const EXPIRY_LEEWAY_SECONDS = 30;

    /**
     * @param string   $accessToken  the token to present to the resource server
     * @param string   $tokenType    the token type, "Bearer" for everything MCP defines
     * @param ?int     $expiresAt    unix timestamp the token expires at, null when the server did not say
     * @param ?string  $refreshToken the refresh token, when the grant produced one
     * @param string[] $scopes       the scopes the authorization server actually granted
     * @param ?string  $clientId     the client the token was issued to, null when unknown
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'Bearer',
        public readonly ?int $expiresAt = null,
        public readonly ?string $refreshToken = null,
        public readonly array $scopes = [],
        public readonly ?string $clientId = null,
    ) {
        if ('' === trim($accessToken)) {
            throw new InvalidArgumentException('The access token must not be empty.');
        }

        if (1 !== preg_match('/^[\x20-\x7E]+$/D', $accessToken)) {
            throw new InvalidArgumentException('The access token contains characters that are not allowed.');
        }

        if (0 !== strcasecmp('Bearer', $tokenType)) {
            throw new InvalidArgumentException('The token type is not supported, only "Bearer" is.');
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

        return new self(
            $response['access_token'],
            \is_string($response['token_type'] ?? null) ? $response['token_type'] : 'Bearer',
            self::expiresAt($response['expires_in'] ?? null, $now ?? time()),
            \is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : null,
            \is_string($response['scope'] ?? null) ? self::splitScopes($response['scope']) : [],
        );
    }

    // The resource server decides about a token that was just issued, so an unusable value is no reason to drop it.
    private static function expiresAt(mixed $expiresIn, int $now): ?int
    {
        if (!is_numeric($expiresIn) || (float) $expiresIn <= 0.0 || (float) $expiresIn >= (float) (\PHP_INT_MAX - $now)) {
            return null;
        }

        return $now + (int) $expiresIn;
    }

    public function isExpired(?int $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return ($now ?? time()) >= $this->expiresAt;
    }

    public function isExpiringSoon(?int $now = null): bool
    {
        return $this->isExpired(($now ?? time()) + self::EXPIRY_LEEWAY_SECONDS);
    }

    /**
     * Whether every one of the given scopes was granted.
     *
     * @param string[] $scopes
     */
    public function covers(array $scopes): bool
    {
        if ([] === $this->scopes) {
            // RFC 6749 section 5.1: no reported scope means that what was requested was granted.
            return true;
        }

        return [] === array_diff($scopes, $this->scopes);
    }

    /**
     * Record the requested scopes when the server reported none (RFC 6749 section 5.1).
     *
     * @param string[] $scopes
     */
    public function withGrantedScopes(array $scopes): self
    {
        if ([] !== $this->scopes || [] === $scopes) {
            return $this;
        }

        return new self($this->accessToken, $this->tokenType, $this->expiresAt, $this->refreshToken, $scopes, $this->clientId);
    }

    public function withClientId(string $clientId): self
    {
        return new self($this->accessToken, $this->tokenType, $this->expiresAt, $this->refreshToken, $this->scopes, $clientId);
    }

    /**
     * Whether the token may be used by this client: a token and its refresh token belong to the client they were issued to (RFC 6749 section 6).
     */
    public function isIssuedTo(?string $clientId): bool
    {
        return null === $this->clientId || $this->clientId === $clientId;
    }

    public function getAuthorizationHeader(): string
    {
        return 'Bearer '.$this->accessToken;
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
            'client_id' => $this->clientId,
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
            \is_string($data['client_id'] ?? null) ? $data['client_id'] : null,
        );
    }

    /**
     * @return string[]
     */
    private static function splitScopes(string $scope): array
    {
        return preg_split('/\s+/', $scope, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
