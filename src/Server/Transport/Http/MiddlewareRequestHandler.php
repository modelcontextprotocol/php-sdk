<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request handler that processes a middleware pipeline before dispatching
 * the request to the core transport handler.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 *
 * @internal
 */
class MiddlewareRequestHandler implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private \Closure $application,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_shift($this->middleware);
        if (null === $middleware) {
            return ($this->application)($request);
        }

        return $middleware->process($request, $this);
    }
}
