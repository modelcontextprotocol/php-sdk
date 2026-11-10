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

use Psr\Http\Client\ClientExceptionInterface;

/**
 * @author Erik Thiart
 */
final class JwksRefreshRateLimitException extends RuntimeException implements ClientExceptionInterface
{
    public function __construct(
        public readonly int $retryAfter,
    ) {
        parent::__construct('JWKS refresh rate limit reached.');
    }
}
