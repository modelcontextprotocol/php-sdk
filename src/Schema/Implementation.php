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

use Mcp\Exception\InvalidArgumentException;

/**
 * Describes the name and version of an MCP implementation.
 *
 * @phpstan-import-type IconData from Icon
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Implementation implements \JsonSerializable
{
    /**
     * @param ?Icon[] $icons
     */
    public function __construct(
        public readonly string $name = 'app',
        public readonly string $version = 'dev',
        public readonly ?string $description = null,
        public readonly ?array $icons = null,
        public readonly ?string $websiteUrl = null,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     version: string,
     *     description?: string,
     *     icons?: IconData[],
     *     websiteUrl?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in Implementation data.');
        }
        if (empty($data['version']) || !\is_string($data['version'])) {
            throw new InvalidArgumentException('Invalid or missing "version" in Implementation data.');
        }

        if (isset($data['icons'])) {
            if (!\is_array($data['icons'])) {
                throw new InvalidArgumentException('Invalid "icons" in Implementation data; expected an array.');
            }

            $data['icons'] = array_map(Icon::fromArray(...), $data['icons']);
        }

        return new self(
            $data['name'],
            $data['version'],
            $data['description'] ?? null,
            $data['icons'] ?? null,
            $data['websiteUrl'] ?? null,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     version: string,
     *     description?: string,
     *     icons?: Icon[],
     *     websiteUrl?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'version' => $this->version,
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->icons) {
            $data['icons'] = $this->icons;
        }

        if (null !== $this->websiteUrl) {
            $data['websiteUrl'] = $this->websiteUrl;
        }

        return $data;
    }
}
