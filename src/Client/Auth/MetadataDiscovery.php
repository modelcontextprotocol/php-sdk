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

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finds the documents that describe who guards an MCP server and how to talk to them.
 *
 * Both lookups are a walk down a list of well-known locations, because both
 * specifications grew a second and third spelling: RFC 9728 lets a resource publish
 * under its path or at the host root, and RFC 8414 inserts the issuer's path into the
 * well-known segment while OpenID Connect appends it. The order below is the one the
 * MCP specification prescribes, and it matters -- a server that publishes at its path
 * must never be asked for the root document first.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class MetadataDiscovery
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetch the protected resource metadata for an MCP endpoint.
     *
     * @param string  $resourceUrl the MCP endpoint being called
     * @param ?string $metadataUrl the location the challenge named, which wins over any guess
     */
    public function discoverProtectedResource(string $resourceUrl, ?string $metadataUrl = null): ?ProtectedResourceMetadata
    {
        foreach (null !== $metadataUrl ? [$metadataUrl] : self::protectedResourceCandidates($resourceUrl) as $candidate) {
            $document = $this->fetch($candidate);

            if (null === $document) {
                continue;
            }

            try {
                return ProtectedResourceMetadata::fromArray($document);
            } catch (\Throwable $e) {
                $this->logger->warning('Ignoring malformed protected resource metadata', ['url' => $candidate, 'exception' => $e]);
            }
        }

        return null;
    }

    /**
     * Fetch an authorization server's metadata, verifying it is the server that was asked for.
     *
     * RFC 8414 section 3.3 requires the document's own issuer to match the identifier the
     * well-known URL was built from, compared as strings. A mismatch means the document
     * describes somebody else, and following its endpoints is exactly the redirection an
     * attacker who can plant metadata is after -- so it is discarded, not used.
     *
     * @param string $issuer the issuer identifier, as advertised by the resource
     */
    public function discoverAuthorizationServer(string $issuer): ?AuthorizationServerMetadata
    {
        foreach (self::authorizationServerCandidates($issuer) as $candidate) {
            $document = $this->fetch($candidate);

            if (null === $document) {
                continue;
            }

            try {
                $metadata = AuthorizationServerMetadata::fromArray($document);
            } catch (\Throwable $e) {
                $this->logger->warning('Ignoring malformed authorization server metadata', ['url' => $candidate, 'exception' => $e]);

                continue;
            }

            if ($metadata->issuer !== $issuer) {
                $this->logger->error('Discarding authorization server metadata: the document\'s issuer does not match the issuer it was requested for.', [
                    'expected_issuer' => $issuer,
                    'metadata_issuer' => $metadata->issuer,
                    'url' => $candidate,
                ]);

                return null;
            }

            return $metadata;
        }

        return null;
    }

    /**
     * @return string[]
     */
    public static function protectedResourceCandidates(string $resourceUrl): array
    {
        $parts = parse_url($resourceUrl);

        if (!\is_array($parts)) {
            return [];
        }

        $origin = self::origin($parts);
        $path = trim($parts['path'] ?? '', '/');
        $root = $origin.'/.well-known/oauth-protected-resource';

        return '' === $path ? [$root] : [$root.'/'.$path, $root];
    }

    /**
     * @return string[]
     */
    public static function authorizationServerCandidates(string $issuer): array
    {
        $parts = parse_url($issuer);

        if (!\is_array($parts)) {
            return [];
        }

        $origin = self::origin($parts);
        $path = trim($parts['path'] ?? '', '/');

        if ('' === $path) {
            return [
                $origin.'/.well-known/oauth-authorization-server',
                $origin.'/.well-known/openid-configuration',
            ];
        }

        return [
            $origin.'/.well-known/oauth-authorization-server/'.$path,
            $origin.'/.well-known/openid-configuration/'.$path,
            $origin.'/'.$path.'/.well-known/openid-configuration',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $url): ?array
    {
        $request = $this->requestFactory->createRequest('GET', $url)->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            $this->logger->debug('Metadata request failed', ['url' => $url, 'exception' => $e]);

            return null;
        }

        if (200 !== $response->getStatusCode()) {
            $this->logger->debug('Metadata not available', ['url' => $url, 'status' => $response->getStatusCode()]);

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function origin(array $parts): string
    {
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }
}
