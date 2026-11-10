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
 * Thrown when an authorization server answers with an OAuth error code.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuthErrorException extends AuthorizationException
{
    public function __construct(string $message, public readonly string $error)
    {
        parent::__construct($message);
    }
}
