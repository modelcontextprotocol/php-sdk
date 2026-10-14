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
 * Another request held the session lock for longer than the timeout.
 *
 * Unlike its parent, this means the lock backend works and the session is busy.
 *
 * @author Vitalii Cherepanov <vbcherepanov@gmail.com>
 */
final class SessionLockTimeoutException extends SessionLockException
{
}
