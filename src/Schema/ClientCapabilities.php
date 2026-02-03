<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

/**
 * Capabilities a client may support. Known capabilities are defined here, in this schema, but this is not a closed set:
 * any client can define its own, additional capabilities.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ClientCapabilities implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $experimental
     */
    public function __construct(
        public readonly ?bool $roots = false,
        public readonly ?bool $rootsListChanged = null,
        public readonly ?bool $sampling = null,
        public readonly ?array $experimental = null,
    ) {
    }

    /**
     * @param array{
     *     roots?: array{
     *         listChanged?: bool,
     *     }|object,
     *     sampling?: object|bool,
     *     experimental?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $rootsEnabled = isset($data['roots']);
        $rootsListChanged = null;
        if ($rootsEnabled) {
            if (\is_array($data['roots']) && \array_key_exists('listChanged', $data['roots'])) {
                $rootsListChanged = (bool) $data['roots']['listChanged'];
            } elseif (\is_object($data['roots']) && property_exists($data['roots'], 'listChanged')) {
                $rootsListChanged = (bool) $data['roots']->listChanged;
            }
        }

        $sampling = null;
        if (isset($data['sampling'])) {
            $sampling = true;
        }

        return new self(
            $rootsEnabled,
            $rootsListChanged,
            $sampling,
            $data['experimental'] ?? null
        );
    }

    /**
     * @return array{
     *     roots?: object,
     *     sampling?: object,
     *     experimental?: object,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [];
        if ($this->roots || $this->rootsListChanged) {
            $data['roots'] = new \stdClass();
            if ($this->rootsListChanged) {
                $data['roots']->listChanged = $this->rootsListChanged;
            }
        }

        if ($this->sampling) {
            $data['sampling'] = new \stdClass();
        }

        if ($this->experimental) {
            $data['experimental'] = (object) $this->experimental;
        }

        return $data;
    }
}
