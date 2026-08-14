<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\Loopback;

use Mcp\Client\Transport\BaseTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Psr\Log\LoggerInterface;

/**
 * Client transport talking to a server in the same process.
 *
 * Sending drains the whole exchange, so a response is usually waiting by the
 * time the protocol looks for one and the request Fiber never suspends. It does
 * suspend when the server asks something back mid-request — elicitation,
 * sampling, roots — and that answer arrives within the same drain.
 *
 * @phpstan-import-type McpFiber from TransportInterface
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class LoopbackClientTransport extends BaseTransport
{
    /** @var (callable(float, ?float, ?string): void)|null */
    private $progressCallback;

    public function __construct(
        private readonly LoopbackConnection $connection,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
    }

    public function connect(): void
    {
        /** @var McpFiber $fiber */
        $fiber = new \Fiber(fn () => $this->handleInitialize());

        $result = $this->run($fiber);

        if ($result instanceof Error) {
            $this->close();

            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('Loopback client connected and initialized');
    }

    public function send(string $data): void
    {
        $this->connection->toServer($data);
        $this->connection->drain();
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        $this->progressCallback = $onProgress;

        try {
            return $this->run($fiber);
        } finally {
            $this->progressCallback = null;
        }
    }

    /**
     * Hand a server message to the protocol.
     */
    public function receive(string $payload): void
    {
        $this->handleMessage($payload);
    }

    public function close(): void
    {
        $this->handleClose('Loopback transport closed');
    }

    /**
     * @param McpFiber $fiber
     *
     * @return Response<array<string, mixed>>|Error
     */
    private function run(\Fiber $fiber): Response|Error
    {
        $suspend = $fiber->start();

        while (!$fiber->isTerminated()) {
            $this->connection->drain();
            $this->flushProgress();

            $response = $this->state?->consumeResponse($suspend['request_id']);

            if (null === $response) {
                // The exchange settled without an answer, so waiting longer cannot
                // help: either the server never produced one, or it is still
                // suspended waiting on this client. Failing here keeps the stall
                // attributable instead of hanging the suite.
                throw new ConnectionException(\sprintf('Loopback exchange settled without a response to request %d.', $suspend['request_id']));
            }

            $suspend = $fiber->resume($response);
        }

        $this->flushProgress();

        return $fiber->getReturn();
    }

    /**
     * Report progress notifications the protocol stored while the exchange ran.
     */
    private function flushProgress(): void
    {
        if (null === $this->progressCallback || null === $this->state) {
            return;
        }

        foreach ($this->state->consumeProgressUpdates() as $update) {
            ($this->progressCallback)($update['progress'], $update['total'], $update['message']);
        }
    }
}
