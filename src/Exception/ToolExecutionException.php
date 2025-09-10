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

namespace Mcp\Exception;

use RuntimeException;
use Throwable;
use Mcp\Schema\Request\CallToolRequest;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ToolExecutionException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly CallToolRequest $request,
        ?Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Execution of tool "%s" failed with error: "%s".', $request->name, $previous?->getMessage() ?? ''), previous: $previous);
    }
}
