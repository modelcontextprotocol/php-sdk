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

use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ResourceUnsubscribeRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
final class ResourceUnsubscribeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly SubscriptionManagerInterface $subscriptionManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ResourceUnsubscribeRequest;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ResourceUnsubscribeRequest);

        $uri = $request->uri;

        try {
            $this->registry->getResource($uri);
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri]);

            return Error::forResourceNotFound($e->getMessage(), $request->getId());
        }

        $this->logger->debug('Unsubscribing from resource', ['uri' => $uri]);

        $this->subscriptionManager->unsubscribe($session, $uri);

        return new Response(
            $request->getId(),
            new EmptyResult(),
        );
    }
}
