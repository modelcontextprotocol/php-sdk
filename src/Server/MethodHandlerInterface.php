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

namespace Mcp\Server;

use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool;

    /**
     * @throws ExceptionInterface When the handler encounters an error processing the request
     */
    public function handle(HasMethodInterface $message): Response|Error|null;
}
