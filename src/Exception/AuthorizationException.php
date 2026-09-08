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
 * Thrown when a client cannot obtain the credentials a protected MCP server demands.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class AuthorizationException extends \RuntimeException implements ExceptionInterface
{
}
