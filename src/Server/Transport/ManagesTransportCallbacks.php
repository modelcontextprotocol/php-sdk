<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A trait for managing the various callbacks provided by the Protocol layer.
 *
 * @phpstan-import-type FiberReturn from \Mcp\Server\Transport\TransportInterface
 * @phpstan-import-type FiberResume from \Mcp\Server\Transport\TransportInterface
 * @phpstan-import-type FiberSuspend from \Mcp\Server\Transport\TransportInterface
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * */
trait ManagesTransportCallbacks
{
    /** @var callable(string, ?Uuid): void */
    protected $messageListener;

    /** @var callable(Uuid): void */
    protected $sessionEndListener;

    /** @var callable(Uuid): array<int, array{message: string, context: array<string, mixed>}> */
    protected $outgoingMessagesProvider;

    /** @var callable(Uuid): array<int, array<string, mixed>> */
    protected $pendingRequestsProvider;

    /** @var callable(int, Uuid): Response<array<string, mixed>>|Error|null */
    protected $responseFinder;

    /** @var callable(FiberSuspend|null, ?Uuid): void */
    protected $fiberYieldHandler;

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionEndListener = $listener;
    }

    public function setOutgoingMessagesProvider(callable $provider): void
    {
        $this->outgoingMessagesProvider = $provider;
    }

    public function setPendingRequestsProvider(callable $provider): void
    {
        $this->pendingRequestsProvider = $provider;
    }

    /**
     * @param callable(int, Uuid):(Response<array<string, mixed>>|Error|null) $finder
     */
    public function setResponseFinder(callable $finder): void
    {
        $this->responseFinder = $finder;
    }

    /**
     * @param callable(FiberSuspend|null, ?Uuid): void $handler
     */
    public function setFiberYieldHandler(callable $handler): void
    {
        $this->fiberYieldHandler = $handler;
    }
}
