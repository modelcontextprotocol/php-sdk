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
 * OAuth 2.0 Protected Resource Metadata (RFC 9728).
 *
 * MCP servers MUST implement this to indicate the locations of authorization servers.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class ProtectedResourceMetadata implements \JsonSerializable
{
    /**
     * @param string $resource The protected resource identifier (canonical URI)
     * @param string[] $authorizationServers List of authorization server issuer identifiers
     * @param string[]|null $scopesSupported OAuth 2.0 scopes supported by the resource
     * @param string[]|null $bearerMethodsSupported Bearer token methods supported
     * @param string|null $resourceDocumentation URL of documentation
     * @param string[]|null $resourceSigningAlgValuesSupported Signing algorithms supported
     * @param string|null $resourceName Human-readable name
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $authorizationServers,
        public readonly ?array $scopesSupported = null,
        public readonly ?array $bearerMethodsSupported = ['header'],
        public readonly ?string $resourceDocumentation = null,
        public readonly ?array $resourceSigningAlgValuesSupported = null,
        public readonly ?string $resourceName = null,
    ) {
        if (empty($authorizationServers)) {
            throw new \InvalidArgumentException('At least one authorization server must be specified.');
        }

        // Validate resource URI format (RFC 3986)
        if (!filter_var($resource, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Resource must be a valid URI.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
        ];

        if (null !== $this->scopesSupported) {
            $data['scopes_supported'] = $this->scopesSupported;
        }

        if (null !== $this->bearerMethodsSupported) {
            $data['bearer_methods_supported'] = $this->bearerMethodsSupported;
        }

        if (null !== $this->resourceDocumentation) {
            $data['resource_documentation'] = $this->resourceDocumentation;
        }

        if (null !== $this->resourceSigningAlgValuesSupported) {
            $data['resource_signing_alg_values_supported'] = $this->resourceSigningAlgValuesSupported;
        }

        if (null !== $this->resourceName) {
            $data['resource_name'] = $this->resourceName;
        }

        return $data;
    }
}

