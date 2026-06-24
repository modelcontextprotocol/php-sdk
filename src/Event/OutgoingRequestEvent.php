<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Event;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Event dispatched when the server sends a request to the client (e.g. elicitation/create, sampling/create).
 *
 * @author Olivier Mouren <mouren.olivier@gmail.com>
 */
final class OutgoingRequestEvent
{
    public function __construct(
        private readonly Request $request,
        private readonly int $timeout,
        private readonly SessionInterface $session,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getMethod(): string
    {
        return $this->request::getMethod();
    }
}
