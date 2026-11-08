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
 * A handler needs a session on a server built without {@see \Mcp\Server\Builder::setSession()}; the protocol answers `-32603` with this message.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SessionRequiredException extends \LogicException implements ExceptionInterface
{
}
