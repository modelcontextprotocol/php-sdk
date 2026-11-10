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

use Mcp\Schema\Exception\InvalidArgumentException;

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
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
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
        if (isset($data['csp']) && !\is_array($data['csp'])) {
            throw new InvalidArgumentException('Invalid "csp" in UiResourceContentMeta data; expected an array.');
        }
        if (isset($data['permissions']) && !\is_array($data['permissions'])) {
            throw new InvalidArgumentException('Invalid "permissions" in UiResourceContentMeta data; expected an array.');
        }
        if (isset($data['domain']) && !\is_string($data['domain'])) {
            throw new InvalidArgumentException('Invalid "domain" in UiResourceContentMeta data.');
        }
        if (isset($data['prefersBorder']) && !\is_bool($data['prefersBorder'])) {
            throw new InvalidArgumentException('Invalid "prefersBorder" in UiResourceContentMeta data.');
        }

        return new self(
            csp: isset($data['csp']) ? UiResourceCsp::fromArray($data['csp']) : null,
            permissions: isset($data['permissions']) ? UiResourcePermissions::fromArray($data['permissions']) : null,
            domain: $data['domain'] ?? null,
            prefersBorder: $data['prefersBorder'] ?? null,
        );
    }

    /**
     * @return array{
     *     csp?: UiResourceCsp,
     *     permissions?: UiResourcePermissions,
     *     domain?: string,
     *     prefersBorder?: bool
     * }
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
}
