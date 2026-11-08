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

use Psr\Http\Client\ClientExceptionInterface;

/**
 * @internal
 *
 * @author Erik Thiart
 */
final class JwksRefreshRateLimitException extends \RuntimeException implements ExceptionInterface, ClientExceptionInterface
{
}
