<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Authorization;

/**
 * The validated access token a request was authorized with.
 *
 * Set by the transport's authorization layer and exposed to handlers through
 * {@see \Mcp\Server\RequestContext::getAccessToken()}. It only lives for the
 * request it arrived with: it is never written to a session store, so a
 * request without a token never inherits an earlier one.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AccessToken implements \JsonSerializable
{
    /**
     * @param list<string>         $scopes scopes granted to the token
     * @param array<string, mixed> $claims claims of the token, e.g. a JWT payload or an introspection response
     */
    public function __construct(
        private readonly array $scopes = [],
        private readonly array $claims = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getClaims(): array
    {
        return $this->claims;
    }

    public function getClaim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * The resource owner the token was issued for (`sub`).
     */
    public function getSubject(): ?string
    {
        $subject = $this->claims['sub'] ?? null;

        return \is_string($subject) ? $subject : null;
    }

    /**
     * The client the token was issued to: `client_id` (RFC 9068), falling back to `azp` (OpenID Connect).
     */
    public function getClientId(): ?string
    {
        $clientId = $this->claims['client_id'] ?? $this->claims['azp'] ?? null;

        return \is_string($clientId) ? $clientId : null;
    }

    /**
     * Never serialized: the token must not outlive its request in a session store.
     */
    public function jsonSerialize(): mixed
    {
        return null;
    }
}
