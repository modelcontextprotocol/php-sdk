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
        public readonly array $scopes,
        public readonly AuthorizationServerMetadata $metadata,
        public readonly string $clientId,
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
            'scopes' => $this->scopes,
            'metadata' => $this->metadata->jsonSerialize(),
            'client_id' => $this->clientId,
            'expires_at' => $this->expiresAt,
            'offline_access_refused' => $this->offlineAccessRefused,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the record is incomplete or malformed
     */
    public static function fromArray(array $data): self
    {
        foreach (['state', 'code_verifier', 'redirect_uri', 'resource', 'client_id'] as $key) {
            if (!\is_string($data[$key] ?? null) || '' === $data[$key]) {
                throw new InvalidArgumentException(\sprintf('Pending authorization is missing "%s".', $key));
            }
        }

        if (!\is_int($data['expires_at'] ?? null) || !\is_array($data['scopes'] ?? null) || !\is_array($data['metadata'] ?? null)) {
            throw new InvalidArgumentException('Invalid pending authorization.');
        }

        $scopes = [];

        foreach ($data['scopes'] as $scope) {
            if (!\is_string($scope)) {
                throw new InvalidArgumentException('Invalid pending authorization scope.');
            }

            $scopes[] = $scope;
        }

        return new self(
            state: $data['state'],
            codeVerifier: $data['code_verifier'],
            redirectUri: $data['redirect_uri'],
            resource: $data['resource'],
            scopes: $scopes,
            metadata: AuthorizationServerMetadata::fromArray($data['metadata']),
            clientId: $data['client_id'],
            expiresAt: $data['expires_at'],
            offlineAccessRefused: true === ($data['offline_access_refused'] ?? false),
        );
    }
}
