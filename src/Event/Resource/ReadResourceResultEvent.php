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
use Mcp\Schema\Result\ReadResourceResult;

/**
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
class ReadResourceResultEvent extends AbstractReadResourceEvent
{
    public function __construct(
        ReadResourceRequest $request,
        private readonly ReadResourceResult $result,
    ) {
        parent::__construct($request);
    }

    public function getResult(): ReadResourceResult
    {
        return $this->result;
    }
}
