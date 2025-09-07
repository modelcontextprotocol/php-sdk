<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\JsonRpc;

use Mcp\Exception\ExceptionInterface;

/**
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
interface HandlerInterface
{
    /**
     * @return iterable<string|null>
     *
     * @throws ExceptionInterface When a handler throws an exception during message processing
     * @throws \JsonException     When JSON encoding of the response fails
     */
    public function process(string $input): iterable;
}
