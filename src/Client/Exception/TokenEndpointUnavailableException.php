<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Exception;

/**
 * Thrown when the token endpoint can't answer right now, which says nothing about the credentials sent to it.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TokenEndpointUnavailableException extends AuthorizationException
{
}
