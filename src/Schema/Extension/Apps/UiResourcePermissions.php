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
 * Sandbox permissions that an MCP App resource requests from the host.
 *
 * @phpstan-type UiResourcePermissionsData array{
 *     camera?: bool,
 *     microphone?: bool,
 *     geolocation?: bool,
 *     clipboardWrite?: bool
 * }
 */
final class UiResourcePermissions implements \JsonSerializable
{
    public function __construct(
        public readonly ?bool $camera = null,
        public readonly ?bool $microphone = null,
        public readonly ?bool $geolocation = null,
        public readonly ?bool $clipboardWrite = null,
    ) {
    }

    /**
     * @param UiResourcePermissionsData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            camera: $data['camera'] ?? null,
            microphone: $data['microphone'] ?? null,
            geolocation: $data['geolocation'] ?? null,
            clipboardWrite: $data['clipboardWrite'] ?? null,
        );
    }

    /**
     * @return UiResourcePermissionsData
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if (null !== $this->camera) {
            $data['camera'] = $this->camera;
        }
        if (null !== $this->microphone) {
            $data['microphone'] = $this->microphone;
        }
        if (null !== $this->geolocation) {
            $data['geolocation'] = $this->geolocation;
        }
        if (null !== $this->clipboardWrite) {
            $data['clipboardWrite'] = $this->clipboardWrite;
        }

        return $data;
    }
}
