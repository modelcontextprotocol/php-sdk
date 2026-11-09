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

/**
 * Takes the user to the authorization endpoint and returns the answer of the authorization server.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface AuthorizationHandlerInterface
{
    /**
     * Send the user to $authorizationUrl and return the query parameters of the redirect to $redirectUri, unchecked.
     *
     * @return array<string, string> the callback's query parameters
     *
     * @throws \Mcp\Exception\AuthorizationException when the user could not be sent, or did not come back
     */
    public function authorize(string $authorizationUrl, string $redirectUri): array;
}
