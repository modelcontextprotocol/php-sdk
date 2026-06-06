<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InvalidInputMessageException extends \InvalidArgumentException implements ExceptionInterface
{
    private string|int|null $requestId = null;

    public function getRequestId(): string|int|null
    {
        return $this->requestId;
    }

    public function setRequestId(string|int|null $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }
}
