<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\Result;

use stdClass;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * A generic empty result that indicates success but carries no data.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class EmptyResult implements ResultInterface
{
    public static function fromArray(): self
    {
        return new self();
    }

    /**
     * @return array{}
     */
    public function jsonSerialize(): object
    {
        return new stdClass();
    }
}
