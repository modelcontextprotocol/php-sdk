<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Middleware;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @internal
 *
 * @implements RequestHandlerInterface<ResultInterface|array<string, mixed>>
 *
 * @author OpenAI
 */
final class RequestMiddlewareHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(Request, SessionInterface): (Response<ResultInterface|array<string, mixed>>|Error) $handle
     */
    private function __construct(
        private readonly Request $original,
        private readonly SessionInterface $session,
        private readonly \Closure $handle,
    ) {
    }

    /**
     * @param list<RequestMiddlewareInterface>                                                            $middleware
     * @param \Closure(Request, SessionInterface): (Response<ResultInterface|array<string, mixed>>|Error) $terminal
     */
    public static function wrap(array $middleware, Request $request, SessionInterface $session, \Closure $terminal): self
    {
        $handler = new self($request, $session, $terminal);
        foreach (array_reverse($middleware) as $layer) {
            $next = $handler;
            $handler = new self($request, $session, static fn (Request $request, SessionInterface $session): Response|Error => $layer->processRequest($request, $session, $next));
        }

        return $handler;
    }

    public function supports(Request $request): bool
    {
        return $this->original::class === $request::class && $request->getId() === $this->original->getId();
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if (!$this->supports($request) || $session !== $this->session) {
            throw new LogicException('Request middleware must preserve the request type, id and session.');
        }

        $result = ($this->handle)($request, $session);

        if ($result->getId() !== $request->getId()) {
            throw new LogicException('Request middleware must preserve the response id.');
        }

        return $result;
    }
}
