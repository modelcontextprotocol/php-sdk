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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Session\SessionInterface;

/**
 * Event dispatched when the server receives a client response to a prior outgoing request.
 *
 * @author Olivier Mouren <mouren.olivier@gmail.com>
 */
final class ClientResponseEvent
{
    /**
     * @param Response<mixed>|Error $response
     */
    public function __construct(
        private readonly Response|Error $response,
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * @return Response<mixed>|Error
     */
    public function getResponse(): Response|Error
    {
        return $this->response;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getId(): string|int
    {
        return $this->response->getId();
    }

    public function isError(): bool
    {
        return $this->response instanceof Error;
    }
}
