<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Context related to a single request. This includes information about the session and
 * will build request-specific objects.
 *
 * This is a stateful object, it should not be reused between requests.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class RequestContext
{
    private ?ClientGateway $clientGateway = null;
    private ?ClientLogger $clientLogger = null;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Request $request,
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

    public function getClientGateway(): ClientGateway
    {
        if (null == $this->clientGateway) {
            $this->clientGateway = new ClientGateway($this->session);
        }

        return $this->clientGateway;
    }

    public function getClientLogger(): ClientLogger
    {
        if (null === $this->clientLogger) {
            $this->clientLogger = new ClientLogger($this->getClientGateway(), $this->session);
        }

        return $this->clientLogger;
    }
}
