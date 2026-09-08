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
 * What an MCP server publishes about itself as a protected resource (RFC 9728).
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ProtectedResourceMetadata
{
    /**
     * @param string    $resource             the canonical URI the resource identifies itself by
     * @param string[]  $authorizationServers issuer identifiers of the servers that can mint tokens for it
     * @param ?string[] $scopesSupported      scopes the resource understands, or null when it does not say
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $authorizationServers = [],
        public readonly ?array $scopesSupported = null,
    ) {
        if ('' === trim($resource)) {
            throw new InvalidArgumentException('The protected resource metadata must name a resource.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['resource']) || !\is_string($data['resource'])) {
            throw new InvalidArgumentException('The protected resource metadata is missing a string "resource".');
        }

        $scopes = $data['scopes_supported'] ?? null;

        return new self(
            $data['resource'],
            array_values(array_filter((array) ($data['authorization_servers'] ?? []), 'is_string')),
            \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : null,
        );
    }

    /**
     * Whether a token minted for this resource may be sent to the given URL.
     *
     * The resource identifier has to cover the endpoint being called: same origin, and
     * a path the endpoint sits under. A metadata document naming somewhere else is the
     * shape a token-leak attack takes, so it is refused rather than followed.
     */
    public function covers(string $url): bool
    {
        return self::isWithin($url, $this->resource);
    }

    /**
     * Whether $url sits at or below the resource identifier $resource.
     *
     * Exposed statically because the same question has to be asked again on every
     * outgoing request, long after the metadata document itself is out of scope: a token
     * minted for one resource must not be attached to a request aimed at another.
     */
    public static function isWithin(string $url, string $resource): bool
    {
        $resource = parse_url($resource);
        $target = parse_url($url);

        if (!\is_array($resource) || !\is_array($target)) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (($resource[$part] ?? null) !== ($target[$part] ?? null)) {
                return false;
            }
        }

        $resourcePath = rtrim($resource['path'] ?? '', '/');
        $targetPath = $target['path'] ?? '';

        return '' === $resourcePath
            || $targetPath === $resourcePath
            || str_starts_with($targetPath, $resourcePath.'/');
    }
}
