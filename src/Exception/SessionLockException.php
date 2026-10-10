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
 * A session lock could not be acquired or released.
 *
 * @author Vitalii Cherepanov <vbcherepanov@gmail.com>
 */
class SessionLockException extends \RuntimeException implements ExceptionInterface
{
}
