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

namespace Mcp\Event\Resource;

use Mcp\Schema\Request\ReadResourceRequest;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class ReadResourceExceptionEvent
{
    public function __construct(
        private readonly ReadResourceRequest $request,
        private readonly \Throwable $throwable,
    ) {
    }

    public function getRequest(): ReadResourceRequest
    {
        return $this->request;
    }

    public function getThrowable(): \Throwable
    {
        return $this->throwable;
    }
}
