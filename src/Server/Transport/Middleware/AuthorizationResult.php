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

/**
 * Describes the outcome of an authorization decision.
 *
 * Use the static factory methods to create instances:
 * - allow() - Access is granted
 * - unauthorized() - No valid credentials provided (401)
 * - forbidden() - Valid credentials but insufficient permissions (403)
 * - badRequest() - Malformed request (400)
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class AuthorizationResult
{
    /**
     * @param list<string>|null $scopes Scopes to include in WWW-Authenticate challenge
     * @param array<string, mixed> $attributes Attributes to attach to the request on success
     */
    private function __construct(
        private readonly bool $allowed,
        private readonly int $statusCode,
        private readonly ?string $error,
        private readonly ?string $errorDescription,
        private readonly ?array $scopes,
        private readonly array $attributes,
    ) {
    }

    /**
     * Creates a result indicating access is allowed.
     *
     * @param array<string, mixed> $attributes Attributes to attach to the request (e.g., user_id, scopes)
     */
    public static function allow(array $attributes = []): self
    {
        return new self(true, 200, null, null, null, $attributes);
    }

    /**
     * Creates a result indicating the request is unauthorized (401).
     *
     * Use when no valid credentials are provided or the token is invalid.
     *
     * @param string|null $error OAuth error code (e.g., "invalid_token")
     * @param string|null $errorDescription Human-readable error description
     * @param list<string>|null $scopes Required scopes to include in challenge
     */
    public static function unauthorized(
        ?string $error = null,
        ?string $errorDescription = null,
        ?array $scopes = null,
    ): self
    {
        return new self(false, 401, $error, $errorDescription, $scopes, []);
    }

    /**
     * Creates a result indicating the request is forbidden (403).
     *
     * Use when the token is valid but lacks required permissions/scopes.
     *
     * @param string|null $error OAuth error code (defaults to "insufficient_scope")
     * @param string|null $errorDescription Human-readable error description
     * @param list<string>|null $scopes Required scopes to include in challenge
     */
    public static function forbidden(
        ?string $error = 'insufficient_scope',
        ?string $errorDescription = null,
        ?array $scopes = null,
    ): self
    {
        return new self(false, 403, $error ?? 'insufficient_scope', $errorDescription, $scopes, []);
    }

    /**
     * Creates a result indicating a bad request (400).
     *
     * Use when the Authorization header is malformed.
     *
     * @param string|null $error OAuth error code (defaults to "invalid_request")
     * @param string|null $errorDescription Human-readable error description
     */
    public static function badRequest(
        ?string $error = 'invalid_request',
        ?string $errorDescription = null,
    ): self
    {
        return new self(false, 400, $error ?? 'invalid_request', $errorDescription, null, []);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorDescription(): ?string
    {
        return $this->errorDescription;
    }

    /**
     * @return list<string>|null
     */
    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
