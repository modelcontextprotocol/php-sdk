<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Mcp\Exception\SessionRequiredException;

/**
 * The session of one request on a server built without {@see \Mcp\Server\Builder::setSession()}.
 *
 * It lives as long as its request and is never stored, so it cannot be saved.
 */
final class EphemeralSession extends Session implements EphemeralSessionInterface
{
    public function __construct()
    {
        parent::__construct(new InMemorySessionStore());
    }

    /**
     * @throws SessionRequiredException always, as nothing in this session outlives its request
     */
    public function save(): never
    {
        throw new SessionRequiredException('Nothing in a session outlives its request without a session store; configure one with Builder::setSession().');
    }
}
