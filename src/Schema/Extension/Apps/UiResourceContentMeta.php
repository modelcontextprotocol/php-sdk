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
 * Metadata for the _meta.ui field on resource content in a resources/read response.
 *
 * This describes the security and display requirements for the rendered HTML app.
 *
 * @phpstan-import-type UiResourceCspData from UiResourceCsp
 * @phpstan-import-type UiResourcePermissionsData from UiResourcePermissions
 *
 * @phpstan-type UiResourceContentMetaData array{
 *     csp?: UiResourceCspData,
 *     permissions?: UiResourcePermissionsData,
 *     domain?: string,
 *     prefersBorder?: bool
 * }
 */
final class UiResourceContentMeta implements \JsonSerializable
{
    public function __construct(
        public readonly ?UiResourceCsp $csp = null,
        public readonly ?UiResourcePermissions $permissions = null,
        public readonly ?string $domain = null,
        public readonly ?bool $prefersBorder = null,
    ) {
    }

    /**
     * @param UiResourceContentMetaData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            csp: isset($data['csp']) ? UiResourceCsp::fromArray($data['csp']) : null,
            permissions: isset($data['permissions']) ? UiResourcePermissions::fromArray($data['permissions']) : null,
            domain: $data['domain'] ?? null,
            prefersBorder: $data['prefersBorder'] ?? null,
        );
    }

    /**
     * @return UiResourceContentMetaData
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if (null !== $this->csp) {
            $data['csp'] = $this->csp;
        }
        if (null !== $this->permissions) {
            $data['permissions'] = $this->permissions;
        }
        if (null !== $this->domain) {
            $data['domain'] = $this->domain;
        }
        if (null !== $this->prefersBorder) {
            $data['prefersBorder'] = $this->prefersBorder;
        }

        return $data;
    }

    /**
     * Returns an array suitable for use as the _meta parameter on resource contents.
     *
     * Usage:
     *
     *     new TextResourceContents(
     *         uri: 'ui://my-app',
     *         mimeType: McpApps::MIME_TYPE,
     *         text: $html,
     *         meta: $contentMeta->toMetaArray(),
     *     )
     *
     * @return array{ui: UiResourceContentMetaData}
     */
    public function toMetaArray(): array
    {
        return ['ui' => $this->jsonSerialize()];
    }
}
