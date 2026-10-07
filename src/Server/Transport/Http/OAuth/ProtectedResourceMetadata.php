<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Mcp\Exception\InvalidArgumentException;

/**
 * OAuth 2.0 Protected Resource Metadata (RFC 9728).
 *
 * The resource identifier is the canonical URI of the MCP server. It decides
 * where the metadata is served — `/.well-known/oauth-protected-resource`
 * followed by the resource's path and query (RFC 9728, Section 3.1) — and which URL the
 * `WWW-Authenticate` challenge points clients to.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtectedResourceMetadata implements \JsonSerializable
{
    private const WELL_KNOWN_PATH = '/.well-known/oauth-protected-resource';

    /** @var list<string> */
    private readonly array $authorizationServers;

    /** @var list<string>|null */
    private readonly ?array $scopesSupported;

    private readonly string $metadataPath;
    private readonly string $metadataUrl;

    /**
     * @param string            $resource             canonical URI of the MCP server, e.g. `https://mcp.example.com/mcp`
     * @param list<string>      $authorizationServers issuer identifiers of the authorization servers that issue tokens for it
     * @param list<string>|null $scopesSupported      minimal scopes for basic functionality
     */
    public function __construct(
        private readonly string $resource,
        array $authorizationServers,
        ?array $scopesSupported = null,
        private readonly ?string $resourceName = null,
        private readonly ?string $resourceDocumentation = null,
    ) {
        $parts = SecureUrl::parse($resource, 'resource');
        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException(\sprintf('The resource "%s" must not contain a fragment.', $resource));
        }

        $authority = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        // Only a slash directly after the host is dropped, any other path is kept verbatim (RFC 9728, Section 3.1).
        $path = $parts['path'] ?? '';
        if ('/' === $path) {
            $path = '';
        }
        $this->metadataPath = self::WELL_KNOWN_PATH.$path;
        $this->metadataUrl = $authority.$this->metadataPath.(isset($parts['query']) ? '?'.$parts['query'] : '');

        foreach ($authorizationServers as $issuer) {
            SecureUrl::parse($issuer, 'authorization server');
        }
        if ([] === $authorizationServers) {
            throw new InvalidArgumentException('Protected resource metadata requires at least one authorization server.');
        }
        $this->authorizationServers = array_values(array_unique($authorizationServers));

        $scopesSupported = Scopes::normalize($scopesSupported ?? []);
        $this->scopesSupported = [] === $scopesSupported ? null : $scopesSupported;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Path the metadata document is served at; a query of the resource is kept in {@see self::getMetadataUrl()}.
     */
    public function getMetadataPath(): string
    {
        return $this->metadataPath;
    }

    /**
     * Absolute URL of the metadata document, as advertised in `WWW-Authenticate`.
     */
    public function getMetadataUrl(): string
    {
        return $this->metadataUrl;
    }

    /**
     * @return list<string>|null
     */
    public function getScopesSupported(): ?array
    {
        return $this->scopesSupported;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
            'scopes_supported' => $this->scopesSupported,
            'bearer_methods_supported' => ['header'],
            'resource_name' => $this->resourceName,
            'resource_documentation' => $this->resourceDocumentation,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
