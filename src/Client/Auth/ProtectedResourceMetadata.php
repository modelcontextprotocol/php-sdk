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
use Mcp\Server\Transport\Http\OAuth\SecureUrl;

/**
 * What an MCP server publishes about itself as a protected resource (RFC 9728).
 *
 * @internal
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

        $parts = SecureUrl::parse($data['resource'], 'protected resource');

        // RFC 9728 section 1.2 rules out fragments; RFC 9110 section 4.2.4 treats untrusted userinfo as an error.
        if (isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(\sprintf('The protected resource "%s" must not have a fragment or userinfo.', $data['resource']));
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
     * Laxer than RFC 9728 section 3.3: on a shared origin, a resource naming a parent path also covers its siblings.
     */
    public function covers(string $url): bool
    {
        return self::isWithin($url, $this->resource);
    }

    /**
     * Whether $url sits at or below the resource identifier $resource.
     */
    public static function isWithin(string $url, string $resource): bool
    {
        // RFC 6750 section 5.3: bearer tokens require TLS, whatever the resource identifier says.
        if (!AuthorizationServerMetadata::isTransportSecure($url)) {
            return false;
        }

        $resource = parse_url($resource);
        $target = parse_url($url);

        if (!\is_array($resource) || !\is_array($target)) {
            return false;
        }

        if (self::origin($resource) !== self::origin($target)) {
            return false;
        }

        if (isset($resource['query']) && ($target['query'] ?? null) !== $resource['query']) {
            return false;
        }

        $resourcePath = $resource['path'] ?? '';

        if (str_ends_with($resourcePath, '/')) {
            $resourcePath = substr($resourcePath, 0, -1);
        }

        $targetPath = $target['path'] ?? '';

        if (self::hasDotSegment($resourcePath) || self::hasDotSegment($targetPath)) {
            return false;
        }

        return '' === $resourcePath
            || $targetPath === $resourcePath
            || str_starts_with($targetPath, $resourcePath.'/');
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int} $parts
     *
     * @return array{string, string, ?int}
     */
    private static function origin(array $parts): array
    {
        $scheme = strtolower($parts['scheme'] ?? '');

        return [$scheme, strtolower($parts['host'] ?? ''), $parts['port'] ?? ['https' => 443, 'http' => 80][$scheme] ?? null];
    }

    private static function hasDotSegment(string $path): bool
    {
        // Rejected rather than resolved, since the server may resolve them differently than we would.
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                return true;
            }
        }

        return false;
    }
}
