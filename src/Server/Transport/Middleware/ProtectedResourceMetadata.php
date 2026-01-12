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
 * Represents OAuth 2.0 Protected Resource Metadata (RFC 9728).
 *
 * This metadata is served at the well-known endpoint to enable clients
 * to discover the authorization servers that protect this resource.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class ProtectedResourceMetadata
{
    /**
     * @param list<string> $authorizationServers URLs of authorization servers that can issue tokens for this resource
     * @param list<string>|null $scopesSupported OAuth scopes supported by this resource
     * @param string|null $resource The resource identifier (typically the resource's URL)
     * @param array<string, mixed> $extra Additional metadata fields
     */
    public function __construct(
        private readonly array $authorizationServers,
        private readonly ?array $scopesSupported = null,
        private readonly ?string $resource = null,
        private readonly array $extra = [],
    ) {
        if (empty($authorizationServers)) {
            throw new \InvalidArgumentException('Protected resource metadata requires at least one authorization server.');
        }
    }

    /**
     * @return list<string>
     */
    public function getAuthorizationServers(): array
    {
        return $this->authorizationServers;
    }

    /**
     * @return list<string>|null
     */
    public function getScopesSupported(): ?array
    {
        return $this->scopesSupported;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'authorization_servers' => array_values($this->authorizationServers),
        ];

        if (null !== $this->scopesSupported) {
            $data['scopes_supported'] = array_values($this->scopesSupported);
        }

        if (null !== $this->resource) {
            $data['resource'] = $this->resource;
        }

        return array_merge($this->extra, $data);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_THROW_ON_ERROR);
    }
}
