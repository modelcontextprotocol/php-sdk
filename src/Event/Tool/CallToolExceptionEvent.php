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

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class CallToolExceptionEvent extends AbstractCallToolEvent
{
    public function __construct(
        CallToolRequest $request,
        private readonly \Throwable $throwable,
    ) {
        parent::__construct($request);
    }

    public function getThrowable(): \Throwable
    {
        return $this->throwable;
    }
}
