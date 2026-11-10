<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Mcp\Exception\AuthorizationPendingException;

/**
 * @author bbdu3 <ergouyang854@gamil.com>
 */
final class RedirectAuthorizationHandler implements AuthorizationHandlerInterface
{
    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        throw new AuthorizationPendingException($authorizationUrl);
    }
}
