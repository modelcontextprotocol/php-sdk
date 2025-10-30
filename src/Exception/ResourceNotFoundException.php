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
final class ResourceNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public function __construct(
        public readonly string $uri,
    ) {
        parent::__construct(\sprintf('Resource not found for uri: "%s".', $uri));
    }
}
