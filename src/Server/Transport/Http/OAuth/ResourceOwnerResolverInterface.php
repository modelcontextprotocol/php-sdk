<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the authenticated end user (resource owner) for an authorization
 * request.
 *
 * The SDK cannot authenticate users or render a login UI — that is the host's
 * responsibility. When no user is authenticated, {@see self::onUnauthenticated()}
 * returns the response that drives the host's login (typically a 302 redirect
 * carrying the current authorize URL so the flow resumes after login).
 */
interface ResourceOwnerResolverInterface
{
    /**
     * @return ResourceOwner|null Null when no end user is authenticated
     */
    public function resolve(ServerRequestInterface $request): ?ResourceOwner;

    public function onUnauthenticated(ServerRequestInterface $request): ResponseInterface;
}
