<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Apps;

/**
 * Content Security Policy configuration for MCP App resources.
 *
 * Controls which external domains the rendered HTML app can access.
 * If omitted entirely, a restrictive default policy is applied by the host.
 *
 * @phpstan-type UiResourceCspData array{
 *     connectDomains?: string[],
 *     resourceDomains?: string[],
 *     frameDomains?: string[],
 *     baseUriDomains?: string[]
 * }
 */
final class UiResourceCsp implements \JsonSerializable
{
    /**
     * @param ?string[] $connectDomains  domains allowed for network requests (fetch, XHR, WebSocket)
     * @param ?string[] $resourceDomains domains allowed for static resources (images, scripts, styles)
     * @param ?string[] $frameDomains    domains allowed for nested iframes
     * @param ?string[] $baseUriDomains  domains allowed for base URI origins
     */
    public function __construct(
        public readonly ?array $connectDomains = null,
        public readonly ?array $resourceDomains = null,
        public readonly ?array $frameDomains = null,
        public readonly ?array $baseUriDomains = null,
    ) {
    }

    /**
     * @param UiResourceCspData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connectDomains: $data['connectDomains'] ?? null,
            resourceDomains: $data['resourceDomains'] ?? null,
            frameDomains: $data['frameDomains'] ?? null,
            baseUriDomains: $data['baseUriDomains'] ?? null,
        );
    }

    /**
     * @return UiResourceCspData
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if (null !== $this->connectDomains) {
            $data['connectDomains'] = $this->connectDomains;
        }
        if (null !== $this->resourceDomains) {
            $data['resourceDomains'] = $this->resourceDomains;
        }
        if (null !== $this->frameDomains) {
            $data['frameDomains'] = $this->frameDomains;
        }
        if (null !== $this->baseUriDomains) {
            $data['baseUriDomains'] = $this->baseUriDomains;
        }

        return $data;
    }
}
