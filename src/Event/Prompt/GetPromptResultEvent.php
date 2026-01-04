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

namespace Mcp\Event\Prompt;

use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class GetPromptResultEvent
{
    public function __construct(
        private readonly GetPromptRequest $request,
        private readonly GetPromptResult $result,
    ) {
    }

    public function getRequest(): GetPromptRequest
    {
        return $this->request;
    }

    public function getResult(): GetPromptResult
    {
        return $this->result;
    }
}
