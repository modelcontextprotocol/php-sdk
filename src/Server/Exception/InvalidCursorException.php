<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Exception;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class InvalidCursorException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $cursor,
    ) {
        parent::__construct(\sprintf('Invalid value for pagination parameter "cursor": "%s"', $cursor));
    }
}
