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
 * Builder for WWW-Authenticate header values per RFC 6750 and MCP spec.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6750#section-3
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class WwwAuthenticateChallenge
{
    public const ERROR_INVALID_REQUEST = 'invalid_request';
    public const ERROR_INVALID_TOKEN = 'invalid_token';
    public const ERROR_INSUFFICIENT_SCOPE = 'insufficient_scope';

    private ?string $realm = null;
    private ?string $error = null;
    private ?string $errorDescription = null;
    private ?string $errorUri = null;
    private ?string $scope = null;
    private ?string $resourceMetadata = null;

    public function withRealm(string $realm): self
    {
        $clone = clone $this;
        $clone->realm = $realm;

        return $clone;
    }

    public function withError(string $error, ?string $description = null, ?string $uri = null): self
    {
        $clone = clone $this;
        $clone->error = $error;
        $clone->errorDescription = $description;
        $clone->errorUri = $uri;

        return $clone;
    }

    /**
     * Set the scope parameter indicating required scopes.
     *
     * @param string|string[] $scope Space-delimited scope string or array of scopes
     */
    public function withScope(string|array $scope): self
    {
        $clone = clone $this;
        $clone->scope = is_array($scope) ? implode(' ', $scope) : $scope;

        return $clone;
    }

    /**
     * Set the resource_metadata parameter pointing to the Protected Resource Metadata document.
     */
    public function withResourceMetadata(string $url): self
    {
        $clone = clone $this;
        $clone->resourceMetadata = $url;

        return $clone;
    }

    /**
     * Build the WWW-Authenticate header value.
     */
    public function build(): string
    {
        $parts = ['Bearer'];

        $params = [];
        if (null !== $this->realm) {
            $params[] = sprintf('realm="%s"', $this->escapeQuotedString($this->realm));
        }

        if (null !== $this->error) {
            $params[] = sprintf('error="%s"', $this->error);
        }

        if (null !== $this->errorDescription) {
            $params[] = sprintf('error_description="%s"', $this->escapeQuotedString($this->errorDescription));
        }

        if (null !== $this->errorUri) {
            $params[] = sprintf('error_uri="%s"', $this->escapeQuotedString($this->errorUri));
        }

        if (null !== $this->scope) {
            $params[] = sprintf('scope="%s"', $this->scope);
        }

        if (null !== $this->resourceMetadata) {
            $params[] = sprintf('resource_metadata="%s"', $this->resourceMetadata);
        }

        if (!empty($params)) {
            return 'Bearer ' . implode(', ', $params);
        }

        return 'Bearer';
    }

    /**
     * Create a 401 Unauthorized challenge for missing/invalid token.
     */
    public static function forUnauthorized(
        string $resourceMetadataUrl,
        ?string $scope = null,
        ?string $errorDescription = null,
    ): self {
        $challenge = (new self())
            ->withResourceMetadata($resourceMetadataUrl);

        if (null !== $scope) {
            $challenge = $challenge->withScope($scope);
        }

        if (null !== $errorDescription) {
            $challenge = $challenge->withError(self::ERROR_INVALID_TOKEN, $errorDescription);
        }

        return $challenge;
    }

    /**
     * Create a 403 Forbidden challenge for insufficient scope.
     *
     * @param string|string[] $requiredScope
     */
    public static function forInsufficientScope(
        string $resourceMetadataUrl,
        string|array $requiredScope,
        ?string $errorDescription = null,
    ): self {
        return (new self())
            ->withError(self::ERROR_INSUFFICIENT_SCOPE, $errorDescription ?? 'Additional scope required')
            ->withScope($requiredScope)
            ->withResourceMetadata($resourceMetadataUrl);
    }

    private function escapeQuotedString(string $value): string
    {
        // Escape backslashes first, then quotes
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function __toString(): string
    {
        return $this->build();
    }
}

