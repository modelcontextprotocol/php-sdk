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

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Attaches credentials to outgoing HTTP requests and reacts to authentication challenges.
 *
 * The two halves are deliberately separate: {@see self::authenticate()} runs on every
 * request and must stay cheap, while {@see self::handleChallenge()} only runs when the
 * resource server actually pushes back, which is where an interactive or network-bound
 * flow belongs.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface AuthenticatorInterface
{
    /**
     * Return the request carrying whatever credentials are currently available.
     *
     * Implementations return the request unchanged when they hold none: the resource
     * server answering with a challenge is what starts the flow.
     */
    public function authenticate(RequestInterface $request): RequestInterface;

    /**
     * React to a 401 or 403 answer from the resource server.
     *
     * @param RequestInterface  $request  the request that was rejected
     * @param ResponseInterface $response the rejecting response, carrying the challenge
     *
     * @return bool true when credentials were obtained and the request should be retried,
     *              false when the authenticator cannot make progress
     */
    public function handleChallenge(RequestInterface $request, ResponseInterface $response): bool;
}
