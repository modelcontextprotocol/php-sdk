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
 * @author bbdu3 <ergouyang854@gmail.com>
 */
final class AuthorizationPendingException extends AuthorizationException
{
    public function __construct(public readonly string $authorizationUrl)
    {
        parent::__construct('Authorization must be completed in a later request.');
    }
}
