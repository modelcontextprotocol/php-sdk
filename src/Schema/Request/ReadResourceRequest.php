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

namespace Mcp\Schema\Request;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

/**
 * Sent from the client to the server, to read a specific resource URI.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class ReadResourceRequest extends Request
{
    /**
     * @param non-empty-string $uri the URI of the resource to read
     */
    public function __construct(
        public readonly string $uri,
    ) {
    }

    public static function getMethod(): string
    {
        return 'resources/read';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['uri']) || !\is_string($params['uri']) || empty($params['uri'])) {
            throw new InvalidArgumentException('Missing or invalid "uri" parameter for resources/read.');
        }

        return new self($params['uri']);
    }

    /**
     * @return array{uri: non-empty-string}
     */
    protected function getParams(): array
    {
        return [
            'uri' => $this->uri,
        ];
    }
}
