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
 * Thrown when the server reports that the current session no longer exists.
 *
 * An HTTP 404 on a request that carried a session id, whose body is not a
 * JSON-RPC error response, means the server has dropped the session. The
 * transport clears the local session id and marks the client un-initialized
 * so it can re-connect and start a new session.
 */
class SessionExpiredException extends ConnectionException
{
}
