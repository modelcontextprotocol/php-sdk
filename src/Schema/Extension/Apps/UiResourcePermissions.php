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
 * Per spec each requested permission is a presence marker (serialized as `{}`),
 * so the wire shape is e.g. `{"geolocation": {}}`, not `{"geolocation": true}`.
 *
 * @phpstan-type UiResourcePermissionsData array{
 *     camera?: \stdClass|array<string, mixed>,
 *     microphone?: \stdClass|array<string, mixed>,
 *     geolocation?: \stdClass|array<string, mixed>,
 *     clipboardWrite?: \stdClass|array<string, mixed>
 * }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class UiResourcePermissions implements \JsonSerializable
{
    public function __construct(
        public readonly bool $camera = false,
        public readonly bool $microphone = false,
        public readonly bool $geolocation = false,
        public readonly bool $clipboardWrite = false,
    ) {
    }

    /**
     * @param UiResourcePermissionsData $data
     */
    public static function fromArray(array $data): self
    {
        // A permission is requested when its key is present with the spec's `{}`
        // marker; isset() accepts that (array/object forms) and rejects a stray null.
        return new self(
            camera: isset($data['camera']),
            microphone: isset($data['microphone']),
            geolocation: isset($data['geolocation']),
            clipboardWrite: isset($data['clipboardWrite']),
        );
    }

    /**
     * @return array<string, \stdClass>
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if ($this->camera) {
            $data['camera'] = new \stdClass();
        }
        if ($this->microphone) {
            $data['microphone'] = new \stdClass();
        }
        if ($this->geolocation) {
            $data['geolocation'] = new \stdClass();
        }
        if ($this->clipboardWrite) {
            $data['clipboardWrite'] = new \stdClass();
        }

        return $data;
    }
}
