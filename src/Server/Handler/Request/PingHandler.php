<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Request;

use Mcp\Event\PingRequestEvent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Session\SessionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof PingRequest;
    }

    /**
     * @return Response<EmptyResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof PingRequest);

        $this->eventDispatcher?->dispatch(new PingRequestEvent($request));

        return new Response($request->getId(), new EmptyResult());
    }
}
