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

use Mcp\Schema\Request\PingRequest;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class PingRequestEvent
{
    public function __construct(
        private readonly PingRequest $request,
    ) {
    }

    public function getRequest(): PingRequest
    {
        return $this->request;
    }
}
