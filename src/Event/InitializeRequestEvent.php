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

namespace Mcp\Event;

use Mcp\Schema\Request\InitializeRequest;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class InitializeRequestEvent
{
    public function __construct(
        private readonly InitializeRequest $request,
    ) {
    }

    public function getRequest(): InitializeRequest
    {
        return $this->request;
    }
}
