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
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Session\SessionInterface;

/**
 * Event dispatched when a successful response is ready to be sent to the client.
 *
 * Listeners can modify the response before it's sent.
 *
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
final class ResponseEvent
{
    /**
     * @param Response<mixed> $response
     */
    public function __construct(
        private Response $response,
        private readonly Request $request,
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * @return Response<mixed>
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @param Response<mixed> $response
     */
    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function getRequest(): Request
    {
        return $this->request;
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
