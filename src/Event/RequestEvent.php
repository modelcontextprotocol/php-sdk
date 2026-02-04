<?php

declare(strict_types=1);

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
 * Event dispatched when any request is received from the client.
 *
 * Listeners can modify the request before it's processed by handlers.
 *
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
final class RequestEvent
{
    public function __construct(
        private Request $request,
        private readonly SessionInterface $session,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getMethod(): string
    {
        return $this->request::getMethod();
    }
}
