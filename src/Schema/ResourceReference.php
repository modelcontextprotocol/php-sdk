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

/**
 * A reference to a resource or resource template definition.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceReference implements JsonSerializable
{
    public string $type = 'ref/resource';

    /**
     * @param string $uri the URI or URI template of the resource
     */
    public function __construct(
        public readonly string $uri,
    ) {
    }

    /**
     * @return array{
     *     type: string,
     *     uri: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'uri' => $this->uri,
        ];
    }
}
