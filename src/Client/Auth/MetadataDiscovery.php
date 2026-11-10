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

use Mcp\Exception\AuthorizationException;
use Mcp\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finds the documents that describe who guards an MCP server, in the order the MCP specification prescribes.
 *
 * @internal
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
     *
     * @return ?ProtectedResourceMetadata the first document that covers the endpoint, null if there is none
     *
     * @throws AuthorizationException if documents were found, but only for other resources
     */
    public function discoverProtectedResource(string $resourceUrl, ?string $metadataUrl = null): ?ProtectedResourceMetadata
    {
        $unrelated = null;

        foreach (null !== $metadataUrl ? [$metadataUrl] : self::protectedResourceCandidates($resourceUrl) as $candidate) {
            if (!AuthorizationServerMetadata::isTransportSecure($candidate)) {
                $this->logger->error('Refusing protected resource discovery: the metadata URL is neither an HTTPS URL nor a loopback address.', ['url' => $candidate]);

                continue;
            }

            $document = $this->fetch($candidate);

            if (null === $document) {
                continue;
            }

            try {
                $metadata = ProtectedResourceMetadata::fromArray($document);
            } catch (InvalidArgumentException $e) {
                $this->logger->warning('Ignoring malformed protected resource metadata', ['url' => $candidate, 'exception' => $e]);

                continue;
            }

            // RFC 9728 section 3.3: the document must not be used, but a later location may describe this resource.
            if (!$metadata->covers($resourceUrl)) {
                $this->logger->warning('Ignoring protected resource metadata for another resource', ['url' => $candidate, 'resource' => $metadata->resource]);
                $unrelated ??= $metadata;

                continue;
            }

            return $metadata;
        }

        if (null !== $unrelated) {
            // A token for another resource would be sent to this one: a confused deputy.
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" declares the unrelated resource "%s"; refusing to authorize against it.', $resourceUrl, $unrelated->resource));
        }

        return null;
    }

    /**
     * Fetch an authorization server's metadata, discarding it if its issuer differs (RFC 8414 section 3.3).
     *
     * @param string $issuer the issuer identifier, as advertised by the resource
     */
    public function discoverAuthorizationServer(string $issuer): ?AuthorizationServerMetadata
    {
        $everyLocationMissing = true;

        return $this->findAuthorizationServer($issuer, $everyLocationMissing);
    }

    /**
     * Find the authorization server of a 2025-03-26 server, which may publish no metadata.
     *
     * That revision only assumes its fixed endpoints after a 404, so any other answer
     * leaves the client without an authorization server.
     *
     * @param string $issuer the origin of the MCP server
     */
    public function discoverLegacyAuthorizationServer(string $issuer): ?AuthorizationServerMetadata
    {
        $everyLocationMissing = true;
        $metadata = $this->findAuthorizationServer($issuer, $everyLocationMissing);

        if (null !== $metadata || !$everyLocationMissing) {
            return $metadata;
        }

        return AuthorizationServerMetadata::forLegacyServer($issuer);
    }

    private function findAuthorizationServer(string $issuer, bool &$everyLocationMissing): ?AuthorizationServerMetadata
    {
        if (!AuthorizationServerMetadata::isTransportSecure($issuer)) {
            $everyLocationMissing = false;
            $this->logger->error('Refusing authorization server discovery: the issuer is neither an HTTPS URL nor a loopback address.', ['issuer' => $issuer]);

            return null;
        }

        foreach (self::authorizationServerCandidates($issuer) as $candidate) {
            $missing = false;
            $document = $this->fetch($candidate, $missing);
            $everyLocationMissing = $everyLocationMissing && $missing;

            if (null === $document) {
                continue;
            }

            try {
                $metadata = AuthorizationServerMetadata::fromArray($document);
            } catch (InvalidArgumentException $e) {
                $this->logger->warning('Ignoring malformed authorization server metadata', ['url' => $candidate, 'exception' => $e]);

                continue;
            }

            if ($metadata->issuer !== $issuer) {
                $this->logger->warning('Discarding authorization server metadata: the document\'s issuer does not match the issuer it was requested for.', [
                    'expected_issuer' => $issuer,
                    'metadata_issuer' => $metadata->issuer,
                    'url' => $candidate,
                ]);

                continue;
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

        $root = self::origin($parts).'/.well-known/oauth-protected-resource';
        $path = $parts['path'] ?? '';
        $suffix = ('/' === $path ? '' : $path).(isset($parts['query']) ? '?'.$parts['query'] : '');

        return '' === $suffix ? [$root] : [$root.$suffix, $root];
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
        $path = substr(rtrim($parts['path'] ?? '', '/'), 1);

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
    private function fetch(string $url, bool &$missing = false): ?array
    {
        $request = $this->requestFactory->createRequest('GET', $url)->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->debug('Metadata request failed', ['url' => $url, 'exception' => $e]);

            return null;
        }

        if (200 !== $response->getStatusCode()) {
            $missing = 404 === $response->getStatusCode();
            $this->logger->debug('Metadata not available', ['url' => $url, 'status' => $response->getStatusCode()]);

            return null;
        }

        $decoded = json_decode(ResponseBody::read($response) ?? '', true);

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
