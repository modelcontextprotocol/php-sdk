<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit;

use Mcp\Client;
use Mcp\Client\Builder;
use Mcp\Client\Transport\BaseTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    #[TestDox('builder() returns a Builder instance')]
    public function testBuilderReturnsBuilderInstance(): void
    {
        $this->assertInstanceOf(Builder::class, Client::builder());
    }

    #[TestDox('connect() succeeds on the first attempt without retrying')]
    public function testConnectSucceedsWithoutRetrying(): void
    {
        $transport = new FakeTransport([FakeTransport::ACCEPT]);

        $client = Client::builder()->build();
        $client->connect($transport);

        $this->assertSame(1, $transport->connectCalls);
        $this->assertSame(0, $transport->closeCalls);
        $this->assertTrue($client->isConnected());
    }

    #[TestDox('connect() retries a failed attempt and succeeds on a later one')]
    public function testConnectRetriesUntilItSucceeds(): void
    {
        $transport = new FakeTransport([FakeTransport::REJECT, FakeTransport::REJECT, FakeTransport::ACCEPT]);

        $client = Client::builder()->setMaxRetries(3)->build();
        $client->connect($transport);

        $this->assertSame(3, $transport->connectCalls);
        $this->assertTrue($client->isConnected());
        $this->assertSame('Test Server', $client->getServerInfo()?->name);
    }

    #[TestDox('connect() closes the transport between two attempts')]
    public function testConnectClosesTransportBetweenAttempts(): void
    {
        $transport = new FakeTransport([FakeTransport::REJECT, FakeTransport::ACCEPT]);

        $client = Client::builder()->setMaxRetries(1)->build();
        $client->connect($transport);

        $this->assertSame(2, $transport->connectCalls);
        $this->assertSame(1, $transport->closeCalls, 'the failed attempt must be cleaned up before the retry');
    }

    #[TestDox('connect() rethrows once the retries are exhausted')]
    public function testConnectThrowsWhenRetriesAreExhausted(): void
    {
        $transport = new FakeTransport([FakeTransport::REJECT, FakeTransport::REJECT, FakeTransport::REJECT]);

        $client = Client::builder()->setMaxRetries(2)->build();

        try {
            $client->connect($transport);
            $this->fail(\sprintf('Expected a "%s" to be thrown.', ConnectionException::class));
        } catch (ConnectionException) {
            // Expected.
        }

        $this->assertSame(3, $transport->connectCalls, 'one initial attempt plus two retries');
        $this->assertSame(3, $transport->closeCalls, 'the last attempt must be cleaned up as well');
        $this->assertFalse($client->isConnected());
    }

    #[TestDox('setMaxRetries(0) disables retrying')]
    public function testZeroRetriesAttemptsToConnectOnce(): void
    {
        $transport = new FakeTransport([FakeTransport::REJECT, FakeTransport::ACCEPT]);

        $client = Client::builder()->setMaxRetries(0)->build();

        $this->expectException(ConnectionException::class);

        try {
            $client->connect($transport);
        } finally {
            $this->assertSame(1, $transport->connectCalls);
        }
    }

    #[TestDox('a timed out attempt does not leave state behind that fails the retry')]
    public function testTimedOutAttemptDoesNotPoisonTheRetry(): void
    {
        // An init timeout of 0 makes the first attempt time out instead of
        // being answered, which is the path that used to leave the request
        // pending forever and time out every following attempt right away.
        $transport = new FakeTransport([FakeTransport::IGNORE, FakeTransport::ACCEPT]);

        $client = Client::builder()->setInitTimeout(0)->setMaxRetries(1)->build();
        $client->connect($transport);

        $this->assertSame(2, $transport->connectCalls);
        $this->assertTrue($client->isConnected());
    }
}

/**
 * Transport whose connection attempts succeed or fail on command.
 *
 * Requests are answered from the polling loop rather than from send(), the way
 * a stdio transport does, with each connect() call answering according to the
 * next configured outcome.
 *
 * @phpstan-import-type McpFiber from TransportInterface
 */
final class FakeTransport extends BaseTransport
{
    /** The initialize request is answered with a result. */
    public const ACCEPT = 'accept';

    /** The initialize request is answered with a JSON-RPC error. */
    public const REJECT = 'reject';

    /** The initialize request is not answered at all and has to time out. */
    public const IGNORE = 'ignore';

    public int $connectCalls = 0;
    public int $closeCalls = 0;

    private string $outcome = self::ACCEPT;

    /** @var list<string> Answers not yet delivered to the client */
    private array $outbox = [];

    /**
     * @param list<self::ACCEPT|self::REJECT|self::IGNORE> $attempts How each successive connect() call behaves
     */
    public function __construct(private array $attempts = [self::ACCEPT])
    {
        parent::__construct();
    }

    public function connect(): void
    {
        ++$this->connectCalls;
        $this->outcome = array_shift($this->attempts) ?? self::ACCEPT;

        $result = $this->runRequest(new \Fiber(fn () => $this->handleInitialize()));

        if ($result instanceof Error) {
            throw new ConnectionException('Initialization failed: '.$result->message);
        }
    }

    public function send(string $data): void
    {
        if (self::IGNORE === $this->outcome) {
            return;
        }

        $message = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);

        if (!isset($message['id'])) {
            return; // A notification, nothing to answer.
        }

        $answer = self::REJECT === $this->outcome
            ? ['error' => ['code' => Error::INTERNAL_ERROR, 'message' => 'Server unavailable']]
            : ['result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => [],
                'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
            ]];

        $this->outbox[] = json_encode(['jsonrpc' => '2.0', 'id' => $message['id']] + $answer, \JSON_THROW_ON_ERROR);
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        $fiber->start();

        for ($poll = 0; !$fiber->isTerminated(); ++$poll) {
            if ($poll > 1000) {
                throw new \LogicException('The fiber never terminated, no pending request became resolvable.');
            }

            foreach ($this->outbox as $answer) {
                $this->handleMessage($answer);
            }
            $this->outbox = [];

            $this->resumeFiber($fiber);
        }

        return $fiber->getReturn();
    }

    public function close(): void
    {
        ++$this->closeCalls;

        $this->handleClose('Transport closed');
    }

    /**
     * @param McpFiber $fiber
     */
    private function resumeFiber(\Fiber $fiber): void
    {
        if (!$fiber->isSuspended() || null === $this->state) {
            return;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            $response = $this->state->consumeResponse($pending['request_id']);

            if (null !== $response) {
                $fiber->resume($response);

                return;
            }

            if (time() - $pending['timestamp'] >= $pending['timeout']) {
                $fiber->resume(Error::forInternalError('Request timed out', $pending['request_id']));

                return;
            }
        }
    }
}
