<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * A generic empty result that indicates success but carries no data.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class EmptyResult implements ResultInterface
{
    /**
     * Create a new EmptyResult.
     */
    public function __construct()
    {
    }

    public static function fromArray(): self
    {
        return new self();
    }

    public function jsonSerialize(): object
    {
        return new \stdClass();
    }
}
