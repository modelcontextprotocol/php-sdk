<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Auth;

/**
 * Interface for validating access tokens.
 *
 * Implementations can validate JWTs, opaque tokens via introspection,
 * or any other token format.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
interface TokenAuthenticatorInterface
{
    /**
     * Authenticate the given access token.
     *
     * @param string      $token    The access token to validate
     * @param string|null $resource The resource URI (MCP server canonical URI) for audience validation
     *
     * @return AuthenticationResult The authentication result with claims if successful
     */
    public function authenticate(string $token, ?string $resource = null): AuthenticationResult;
}

