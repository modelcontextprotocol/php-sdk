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
 * Configuration for OAuth 2.0 authentication.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class OAuth2Configuration
{
    /**
     * @param TokenAuthenticatorInterface $tokenAuthenticator   Token validator
     * @param ProtectedResourceMetadata   $resourceMetadata     Protected resource metadata (RFC 9728)
     * @param string|null                 $resourceMetadataPath Custom path for metadata endpoint (defaults to /.well-known/oauth-protected-resource)
     * @param string[]                    $publicPaths          Paths that don't require authentication
     * @param bool                        $validateAudience     Whether to validate token audience matches resource URL
     */
    public function __construct(
        public readonly TokenAuthenticatorInterface $tokenAuthenticator,
        public readonly ProtectedResourceMetadata $resourceMetadata,
        public readonly ?string $resourceMetadataPath = null,
        public readonly array $publicPaths = [],
        public readonly bool $validateAudience = true,
    ) {
    }

    /**
     * Get the expected audience for token validation.
     *
     * Returns the resource URL if audience validation is enabled, null otherwise.
     */
    public function getExpectedAudience(): ?string
    {
        return $this->validateAudience ? $this->resourceMetadata->resource : null;
    }

    /**
     * Get the path where Protected Resource Metadata should be served.
     */
    public function getResourceMetadataPath(): string
    {
        return $this->resourceMetadataPath ?? '/.well-known/oauth-protected-resource';
    }

    /**
     * Get the full URL for the Protected Resource Metadata document.
     */
    public function getResourceMetadataUrl(): string
    {
        $resource = rtrim($this->resourceMetadata->resource, '/');
        $path = $this->getResourceMetadataPath();

        // If resource already contains path, handle appropriately
        $parsed = parse_url($resource);
        $basePath = $parsed['path'] ?? '';

        if ('' !== $basePath && '/' !== $basePath) {
            // Resource has a path component, metadata goes at /.well-known/oauth-protected-resource{path}
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

            return "{$scheme}://{$host}{$port}/.well-known/oauth-protected-resource{$basePath}";
        }

        return $resource . $path;
    }

    /**
     * Check if a path should bypass authentication.
     */
    public function isPublicPath(string $path): bool
    {
        // Protected Resource Metadata is always public
        if ($path === $this->getResourceMetadataPath()) {
            return true;
        }

        // Handle paths starting with /.well-known/oauth-protected-resource
        if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
            return true;
        }

        foreach ($this->publicPaths as $publicPath) {
            if ($path === $publicPath) {
                return true;
            }
            // Support wildcards
            if (str_ends_with($publicPath, '*') && str_starts_with($path, rtrim($publicPath, '*'))) {
                return true;
            }
        }

        return false;
    }
}

