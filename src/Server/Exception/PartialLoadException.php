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
 * Thrown by a loader that wrote what it could after a part of it failed. The failure is logged and
 * the load stays pending.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PartialLoadException extends RuntimeException
{
}
