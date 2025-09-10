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

namespace Mcp\Schema\JsonRpc;

/**
 * Interface for all incoming JSON-RPC messages that should be processed by a handler, and are expected to have a method.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface HasMethodInterface
{
    public static function getMethod(): string;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self;
}
