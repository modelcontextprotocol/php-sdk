<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

/**
 * A session that lives as long as its request and is never stored.
 *
 * The server refuses what would have to outlast the request on it, like requests to the client and
 * resource subscriptions.
 *
 * @author Ruud Kamphuis <ruudk@users.noreply.github.com>
 */
interface EphemeralSessionInterface extends SessionInterface
{
}
