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

namespace Mcp\Capability\Resource;

use Mcp\Exception\InvalidCursorException;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
interface CollectionInterface
{
    /**
     * @param int $count the number of metadata items to return
     *
     * @return iterable<MetadataInterface>
     *
     * @throws InvalidCursorException if no item with $lastIdentifier was found
     */
    public function getMetadata(int $count, ?string $lastIdentifier = null): iterable;
}
