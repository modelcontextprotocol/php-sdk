<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Auth;

/**
 * Result of an authentication attempt.
 *
 * Contains the authenticated user/client information if successful,
 * or error details if authentication failed.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class AuthenticationResult
{
    /**
     * @param array<string, mixed> $claims  Token claims (subject, scopes, etc.)
     * @param array<string, mixed> $context Additional context about the authentication
     */
    private function __construct(
        public readonly bool $authenticated,
        public readonly array $claims = [],
        public readonly ?string $error = null,
        public readonly ?string $errorDescription = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * Create a successful authentication result.
     *
     * @param array<string, mixed> $claims  Token claims (sub, scope, aud, etc.)
     * @param array<string, mixed> $context Additional context
     */
    public static function authenticated(array $claims, array $context = []): self
    {
        return new self(
            authenticated: true,
            claims: $claims,
            context: $context,
        );
    }

    /**
     * Create a failed authentication result.
     */
    public static function unauthenticated(string $error, ?string $errorDescription = null): self
    {
        return new self(
            authenticated: false,
            error: $error,
            errorDescription: $errorDescription,
        );
    }

    /**
     * Get the subject claim (typically user or client ID).
     */
    public function getSubject(): ?string
    {
        return $this->claims['sub'] ?? null;
    }

    /**
     * Get the scopes from the token.
     *
     * @return string[]
     */
    public function getScopes(): array
    {
        $scope = $this->claims['scope'] ?? '';
        if (is_string($scope)) {
            return array_filter(explode(' ', $scope));
        }
        if (is_array($scope)) {
            return $scope;
        }

        return [];
    }

    /**
     * Check if the token has a specific scope.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getScopes(), true);
    }

    /**
     * Check if the token has all specified scopes.
     *
     * @param string[] $scopes
     */
    public function hasAllScopes(array $scopes): bool
    {
        $tokenScopes = $this->getScopes();
        foreach ($scopes as $scope) {
            if (!in_array($scope, $tokenScopes, true)) {
                return false;
            }
        }

        return true;
    }
}

