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
 * Represents a root directory or file that the server can operate on.
 *
 * @phpstan-type RootData array{
 *     uri: string,
 *     name?: string,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Root implements JsonSerializable
{
    private const URI_PATTERN = '/^file:\/\/.*$/';

    /**
     * @param string $uri The URI identifying the root. This *must* start with file:// for now.
     *
     *  This restriction may be relaxed in future versions of the protocol to allow other URI schemes.
     * @param string|null $name An optional name for the root.
     *
     * This can be used to provide a human-readable identifier for the root, which may be useful for
     * display purposes or for referencing the root in other parts of the application.
     */
    public function __construct(
        public readonly string $uri,
        public readonly ?string $name = null,
    ) {
        if (!preg_match(self::URI_PATTERN, $this->uri)) {
            throw new InvalidArgumentException(\sprintf('Root URI must start with "file://". Given: "%s".', $this->uri));
        }
    }

    /**
     * @param RootData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" in Root data.');
        }

        return new self($data['uri'], $data['name'] ?? null);
    }

    /**
     * @return RootData
     */
    public function jsonSerialize(): array
    {
        $data = ['uri' => $this->uri];
        if (null !== $this->name) {
            $data['name'] = $this->name;
        }

        return $data;
    }
}
