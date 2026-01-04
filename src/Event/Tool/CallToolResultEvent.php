<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Event\Tool;

use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class CallToolResultEvent
{
    public function __construct(
        private readonly CallToolRequest $request,
        private readonly CallToolResult $result,
    ) {
    }

    public function getRequest(): CallToolRequest
    {
        return $this->request;
    }

    public function getResult(): CallToolResult
    {
        return $this->result;
    }
}
