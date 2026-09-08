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
 * Carries the user through the authorization endpoint and brings the answer back.
 *
 * This is the one step of the flow the SDK cannot do on its own: what happens between
 * "here is a URL the user must visit" and "here is what the authorization server
 * redirected to" depends entirely on what the application is -- a terminal, a desktop
 * app with a browser, a web application with a real callback route, or an unattended
 * job against a server that grants without asking.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface AuthorizationHandlerInterface
{
    /**
     * Send the user to $authorizationUrl and return the query parameters the
     * authorization server redirected back to $redirectUri with.
     *
     * The returned array is passed through untouched -- `code`, `state`, `iss` and any
     * `error` are all validated by the caller, so an implementation only has to deliver
     * what it saw.
     *
     * @return array<string, string> the callback's query parameters
     *
     * @throws \Mcp\Exception\AuthorizationException when the user could not be sent, or did not come back
     */
    public function authorize(string $authorizationUrl, string $redirectUri): array;
}
