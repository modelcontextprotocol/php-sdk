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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Event dispatched when an error occurs during request processing.
 *
 * Listeners can modify the error before it's sent to the client.
 *
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
final class ErrorEvent
{
    public function __construct(
        private Error $error,
        private readonly Request $request,
        private readonly SessionInterface $session,
        private readonly ?\Throwable $throwable,
    ) {
    }

    public function getError(): Error
    {
        return $this->error;
    }

    public function setError(Error $error): void
    {
        $this->error = $error;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }
}
