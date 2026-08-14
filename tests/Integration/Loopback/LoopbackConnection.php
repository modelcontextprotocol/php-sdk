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

use Mcp\Exception\RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Wires a real client transport to a real server transport, in one process.
 *
 * Both sides of the SDK drive their own loop: the client transport starts a
 * Fiber per request and resumes it once a response arrives, while the server
 * transport resumes the Fiber a tool handler suspended when it asked the client
 * something. A round-trip like elicitation therefore has both loops suspended at
 * the same time, each waiting on the other.
 *
 * This connection replaces both loops with one drain: messages move between two
 * queues until neither side can make further progress. Everything is
 * synchronous, so there is no polling, no sleeping and no wall clock. A
 * round-trip that would hang over a socket instead settles with the response
 * missing, and the client transport reports that as a failure right where it
 * happened.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class LoopbackConnection
{
    /**
     * Upper bound on drain iterations before the exchange is considered stuck.
     *
     * Only a bug can reach it — a handler that answers its own message forever,
     * say — and without it that bug would hang the suite instead of failing it.
     */
    private const MAX_STEPS = 1000;

    private readonly LoopbackClientTransport $clientTransport;
    private readonly LoopbackServerTransport $serverTransport;

    /** @var list<string> */
    private array $toServer = [];

    /** @var list<string> */
    private array $toClient = [];

    private bool $draining = false;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->clientTransport = new LoopbackClientTransport($this, $logger);
        $this->serverTransport = new LoopbackServerTransport($this, $logger);
    }

    public function clientTransport(): LoopbackClientTransport
    {
        return $this->clientTransport;
    }

    public function serverTransport(): LoopbackServerTransport
    {
        return $this->serverTransport;
    }

    public function toServer(string $payload): void
    {
        $this->toServer[] = $payload;
    }

    public function toClient(string $payload): void
    {
        $this->toClient[] = $payload;
    }

    /**
     * Move messages between both sides until the exchange settles.
     *
     * Answering a message sends one back, so this keeps looping while anything
     * moved in the previous pass rather than draining each queue once.
     */
    public function drain(): void
    {
        // Answering a server request re-enters here through the client's send().
        // The exchange is already being drained by the caller below, which picks
        // the queued message up on its next pass.
        if ($this->draining) {
            return;
        }

        $this->draining = true;

        try {
            $steps = 0;

            do {
                if (++$steps > self::MAX_STEPS) {
                    throw new RuntimeException(\sprintf('Loopback exchange did not settle within %d steps.', self::MAX_STEPS));
                }

                $progressed = false;

                while (null !== ($payload = array_shift($this->toServer))) {
                    $this->serverTransport->deliver($payload);
                    $progressed = true;
                }

                $progressed = $this->serverTransport->pump() || $progressed;

                while (null !== ($payload = array_shift($this->toClient))) {
                    $this->clientTransport->receive($payload);
                    $progressed = true;
                }
            } while ($progressed);
        } finally {
            $this->draining = false;
        }
    }
}
