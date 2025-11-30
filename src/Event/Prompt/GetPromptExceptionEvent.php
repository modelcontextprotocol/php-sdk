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

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class GetPromptExceptionEvent
{
    public function __construct(
        private readonly GetPromptRequest $request,
        private readonly \Throwable $throwable,
    ) {
    }

    public function getRequest(): GetPromptRequest
    {
        return $this->request;
    }

    public function getThrowable(): \Throwable
    {
        return $this->throwable;
    }
}
