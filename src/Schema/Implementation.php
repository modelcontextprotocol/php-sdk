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

use Mcp\Schema\Exception\InvalidArgumentException;

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
     * @param ?string $title Display name for UI and end-user contexts. Falls back to $name when absent.
     */
    public function __construct(
        public readonly string $name = 'app',
        public readonly string $version = 'dev',
        public readonly ?string $description = null,
        public readonly ?array $icons = null,
        public readonly ?string $websiteUrl = null,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     version: string,
     *     description?: string,
     *     icons?: IconData[],
     *     websiteUrl?: string,
     *     title?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['name']) || !\is_string($data['name']) || '' === $data['name']) {
            throw new InvalidArgumentException('Invalid or missing "name" in Implementation data.');
        }
        if (!isset($data['version']) || !\is_string($data['version']) || '' === $data['version']) {
            throw new InvalidArgumentException('Invalid or missing "version" in Implementation data.');
        }

        if (isset($data['icons'])) {
            if (!\is_array($data['icons'])) {
                throw new InvalidArgumentException('Invalid "icons" in Implementation data; expected an array.');
            }

            $data['icons'] = Icon::listFromArray($data['icons'], 'Implementation');
        }

        if (isset($data['description']) && !\is_string($data['description'])) {
            throw new InvalidArgumentException('Invalid "description" in Implementation data.');
        }
        if (isset($data['websiteUrl']) && !\is_string($data['websiteUrl'])) {
            throw new InvalidArgumentException('Invalid "websiteUrl" in Implementation data.');
        }
        if (isset($data['title']) && !\is_string($data['title'])) {
            throw new InvalidArgumentException('Invalid "title" in Implementation data.');
        }

        return new self(
            $data['name'],
            $data['version'],
            $data['description'] ?? null,
            $data['icons'] ?? null,
            $data['websiteUrl'] ?? null,
            $data['title'] ?? null,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     version: string,
     *     description?: string,
     *     icons?: Icon[],
     *     websiteUrl?: string,
     *     title?: string,
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

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        return $data;
    }
}
