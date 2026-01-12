<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Validates bearer tokens for HTTP transports.
 *
 * Implementations should validate the access token and return an AuthorizationResult
 * indicating whether access is allowed or denied.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
interface AuthorizationTokenValidatorInterface
{
    /**
     * Validates an access token extracted from the Authorization header.
     *
     * @param ServerRequestInterface $request The incoming HTTP request
     * @param string $accessToken The bearer token (without "Bearer " prefix)
     *
     * @return AuthorizationResult The result of the validation
     */
    public function validate(ServerRequestInterface $request, string $accessToken): AuthorizationResult;
}
