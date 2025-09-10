<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema;

use JsonSerializable;
use Mcp\Exception\InvalidArgumentException;

/**
 * Describes the name and version of an MCP implementation.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Implementation implements JsonSerializable
{
    public function __construct(
        public readonly string $name = 'app',
        public readonly string $version = 'dev',
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     version: string,
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

        return new self($data['name'], $data['version'], $data['description'] ?? null);
    }

    /**
     * @return array{
     *     name: string,
     *     version: string,
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

        return $data;
    }
}
