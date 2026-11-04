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
 * @author PHP MCP SDK contributors
 */
final class PendingAuthorization implements \JsonSerializable
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly string $redirectUri,
        public readonly string $resource,
        public readonly string $audience,
        public readonly array $scopes,
        public readonly AuthorizationServerMetadata $metadata,
        public readonly ClientRegistration $client,
        public readonly int $expiresAt,
        public readonly bool $offlineAccessRefused = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
            'resource' => $this->resource,
            'audience' => $this->audience,
            'scopes' => $this->scopes,
            'metadata' => [
                'issuer' => $this->metadata->issuer,
                'authorization_endpoint' => $this->metadata->authorizationEndpoint,
                'token_endpoint' => $this->metadata->tokenEndpoint,
                'code_challenge_methods_supported' => $this->metadata->codeChallengeMethodsSupported,
                'authorization_response_iss_parameter_supported' => $this->metadata->issuerParameterSupported,
            ],
            'client' => $this->client->jsonSerialize(),
            'expires_at' => $this->expiresAt,
            'offline_access_refused' => $this->offlineAccessRefused,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['state', 'code_verifier', 'redirect_uri', 'resource', 'audience'] as $key) {
            if (!\is_string($data[$key] ?? null) || '' === $data[$key]) {
                throw new InvalidArgumentException(\sprintf('Pending authorization is missing "%s".', $key));
            }
        }

        if (!\is_int($data['expires_at'] ?? null) || !\is_array($data['scopes'] ?? null) || !\is_array($data['metadata'] ?? null) || !\is_array($data['client'] ?? null)) {
            throw new InvalidArgumentException('Invalid pending authorization.');
        }

        foreach ($data['scopes'] as $scope) {
            if (!\is_string($scope)) {
                throw new InvalidArgumentException('Invalid pending authorization scope.');
            }
        }

        return new self(
            $data['state'], $data['code_verifier'], $data['redirect_uri'],
            $data['resource'], $data['audience'], array_values($data['scopes']),
            AuthorizationServerMetadata::fromArray($data['metadata']),
            ClientRegistration::fromArray($data['client']), $data['expires_at'],
            true === ($data['offline_access_refused'] ?? false),
        );
    }
}
