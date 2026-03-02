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
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtectedResourceMetadata implements \JsonSerializable
{
    public const DEFAULT_METADATA_PATH = '/.well-known/oauth-protected-resource';

    private const LOCALIZED_HUMAN_READABLE_FIELD_PATTERN = '/^(resource_name|resource_documentation|resource_policy_uri|resource_tos_uri)#[A-Za-z0-9-]+$/';

    /** @var list<string> */
    private array $authorizationServers;

    /** @var list<string>|null */
    private ?array $scopesSupported;

    /** @var list<string> */
    private array $metadataPaths;

    /** @var array<string, string> */
    private array $localizedHumanReadable;

    /** @var array<string, mixed> */
    private array $extra;

    private ?string $resource;
    private ?string $resourceName;
    private ?string $resourceDocumentation;
    private ?string $resourcePolicyUri;
    private ?string $resourceTosUri;

    /**
     * @param list<string>          $authorizationServers
     * @param list<string>|null     $scopesSupported
     * @param array<string, string> $localizedHumanReadable Locale-specific values, e.g. resource_name#en => "My Resource"
     * @param array<string, mixed>  $extra                  Additional RFC 9728 metadata fields
     * @param list<string>          $metadataPaths
     */
    public function __construct(
        array $authorizationServers,
        ?array $scopesSupported = null,
        ?string $resource = null,
        ?string $resourceName = null,
        ?string $resourceDocumentation = null,
        ?string $resourcePolicyUri = null,
        ?string $resourceTosUri = null,
        array $localizedHumanReadable = [],
        array $extra = [],
        array $metadataPaths = [self::DEFAULT_METADATA_PATH],
    ) {
        $this->authorizationServers = $this->normalizeStringList($authorizationServers, 'authorizationServers');
        if ([] === $this->authorizationServers) {
            throw new InvalidArgumentException('Protected resource metadata requires at least one authorization server.');
        }

        $normalizedScopes = $this->normalizeStringList($scopesSupported ?? [], 'scopesSupported');
        $this->scopesSupported = [] === $normalizedScopes ? null : $normalizedScopes;

        $this->resource = $this->normalizeNullableString($resource);
        $this->resourceName = $this->normalizeNullableString($resourceName);
        $this->resourceDocumentation = $this->normalizeNullableString($resourceDocumentation);
        $this->resourcePolicyUri = $this->normalizeNullableString($resourcePolicyUri);
        $this->resourceTosUri = $this->normalizeNullableString($resourceTosUri);
        $this->localizedHumanReadable = $this->normalizeLocalizedHumanReadable($localizedHumanReadable);
        $this->extra = $extra;

        $this->metadataPaths = $this->normalizePaths($metadataPaths);
        if ([] === $this->metadataPaths) {
            throw new InvalidArgumentException('Protected resource metadata requires at least one metadata path.');
        }
    }

    /**
     * @return list<string>
     */
    public function getMetadataPaths(): array
    {
        return $this->metadataPaths;
    }

    public function getPrimaryMetadataPath(): string
    {
        return $this->metadataPaths[0];
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
        $data = [
            'authorization_servers' => $this->authorizationServers,
        ];

        if (null !== $this->scopesSupported) {
            $data['scopes_supported'] = $this->scopesSupported;
        }

        if (null !== $this->resource) {
            $data['resource'] = $this->resource;
        }

        if (null !== $this->resourceName) {
            $data['resource_name'] = $this->resourceName;
        }

        if (null !== $this->resourceDocumentation) {
            $data['resource_documentation'] = $this->resourceDocumentation;
        }

        if (null !== $this->resourcePolicyUri) {
            $data['resource_policy_uri'] = $this->resourcePolicyUri;
        }

        if (null !== $this->resourceTosUri) {
            $data['resource_tos_uri'] = $this->resourceTosUri;
        }

        foreach ($this->localizedHumanReadable as $key => $value) {
            $data[$key] = $value;
        }

        return array_merge($this->extra, $data);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizeStringList(array $values, string $parameterName): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new InvalidArgumentException(\sprintf('Protected resource metadata parameter "%s" must contain strings.', $parameterName));
            }

            $value = trim($value);
            if ('' === $value) {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw new InvalidArgumentException('Protected resource metadata paths must be strings.');
            }

            $path = trim($path);
            if ('' === $path) {
                continue;
            }

            if ('/' !== $path[0]) {
                $path = '/'.$path;
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, string> $localizedHumanReadable
     *
     * @return array<string, string>
     */
    private function normalizeLocalizedHumanReadable(array $localizedHumanReadable): array
    {
        $normalized = [];

        foreach ($localizedHumanReadable as $field => $value) {
            if (!\is_string($field) || !preg_match(self::LOCALIZED_HUMAN_READABLE_FIELD_PATTERN, $field)) {
                throw new InvalidArgumentException(\sprintf('Invalid localized human-readable field: "%s".', (string) $field));
            }

            if (!\is_string($value)) {
                throw new InvalidArgumentException(\sprintf('Localized human-readable value for "%s" must be a string.', $field));
            }

            $value = trim($value);
            if ('' === $value) {
                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }
}
