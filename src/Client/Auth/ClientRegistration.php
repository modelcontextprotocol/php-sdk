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
 * The client's identity at one authorization server.
 *
 * Whether it was handed out by dynamic registration, configured up front, or is a URL
 * pointing at a client metadata document, everything downstream needs the same three
 * facts: who the client claims to be, what secret it holds, and how it proves it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ClientRegistration implements \JsonSerializable
{
    /**
     * @param string                  $clientId                the client identifier
     * @param ?string                 $clientSecret            the client secret, when the server issued one
     * @param TokenEndpointAuthMethod $tokenEndpointAuthMethod how the client authenticates at the token endpoint
     * @param bool                    $dynamic                 whether this came from dynamic client registration,
     *                                                         and may therefore be re-created at will
     * @param ?int                    $clientSecretExpiresAt   unix timestamp the secret expires at, 0/null when it does not
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        public readonly TokenEndpointAuthMethod $tokenEndpointAuthMethod = TokenEndpointAuthMethod::None,
        public readonly bool $dynamic = false,
        public readonly ?int $clientSecretExpiresAt = null,
    ) {
        if ('' === trim($clientId)) {
            throw new InvalidArgumentException('The client id must not be empty.');
        }
    }

    /**
     * Build a registration from a parsed RFC 7591 registration response.
     *
     * @param array<string, mixed>    $response the parsed registration response
     * @param TokenEndpointAuthMethod $fallback the method to assume when the server does not echo one back
     */
    public static function fromResponse(array $response, TokenEndpointAuthMethod $fallback = TokenEndpointAuthMethod::None): self
    {
        if (!isset($response['client_id']) || !\is_string($response['client_id'])) {
            throw new InvalidArgumentException('The client registration response is missing a string "client_id".');
        }

        $secret = \is_string($response['client_secret'] ?? null) ? $response['client_secret'] : null;

        // A server that echoes back a method has the final say; one that stays silent
        // accepted what was asked for, which is what $fallback carries.
        $method = TokenEndpointAuthMethod::tryFrom((string) ($response['token_endpoint_auth_method'] ?? '')) ?? $fallback;

        $expiresAt = $response['client_secret_expires_at'] ?? null;

        return new self(
            $response['client_id'],
            $secret,
            $method,
            true,
            is_numeric($expiresAt) && 0 !== (int) $expiresAt ? (int) $expiresAt : null,
        );
    }

    public function isExpired(?int $now = null): bool
    {
        return null !== $this->clientSecretExpiresAt && ($now ?? time()) >= $this->clientSecretExpiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'token_endpoint_auth_method' => $this->tokenEndpointAuthMethod->value,
            'dynamic' => $this->dynamic,
            'client_secret_expires_at' => $this->clientSecretExpiresAt,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
            \is_string($data['client_secret'] ?? null) ? $data['client_secret'] : null,
            TokenEndpointAuthMethod::tryFrom((string) ($data['token_endpoint_auth_method'] ?? '')) ?? TokenEndpointAuthMethod::None,
            (bool) ($data['dynamic'] ?? false),
            isset($data['client_secret_expires_at']) && is_numeric($data['client_secret_expires_at']) ? (int) $data['client_secret_expires_at'] : null,
        );
    }
}
