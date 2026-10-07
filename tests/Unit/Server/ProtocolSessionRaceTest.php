<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Protocol;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Two workers sharing one session: one holds a tool call's SSE stream open and
 * polls the session for the client's answer, the other receives that answer as
 * a separate POST and stores it.
 */
final class ProtocolSessionRaceTest extends TestCase
{
    #[TestDox('polling the session does not overwrite a client response stored meanwhile')]
    public function testPollingDoesNotOverwriteAConcurrentResponse(): void
    {
        $store = new InterleavingSessionStore(new InMemorySessionStore());
        $sessions = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $sessions->createWithId($sessionId)->save();

        $waiting = new Protocol([], [], MessageFactory::make(), $sessions);
        $answering = new Protocol([], [], MessageFactory::make(), $sessions);
        $transport = $this->createMock(TransportInterface::class);

        // The answer lands right after the waiting worker read the session,
        // before anything it does next could write the session back.
        $store->afterNextRead(static function () use ($answering, $transport, $sessionId): void {
            $answering->processInput($transport, '{"jsonrpc": "2.0", "id": 7, "result": {"ok": true}}', $sessionId);
        });

        // One turn of the waiting worker's loop, with nothing queued to send.
        $this->assertSame([], $waiting->consumeOutgoingMessages($sessionId));

        $this->assertInstanceOf(Response::class, $waiting->checkResponse(7, $sessionId));
    }
}

/**
 * Runs a callback once, right after the next read, to interleave another
 * worker's write with the reader's read-modify-write.
 */
final class InterleavingSessionStore implements SessionStoreInterface
{
    /** @var (\Closure(): void)|null */
    private ?\Closure $afterNextRead = null;

    public function __construct(
        private readonly SessionStoreInterface $store,
    ) {
    }

    /**
     * @param \Closure(): void $callback
     */
    public function afterNextRead(\Closure $callback): void
    {
        $this->afterNextRead = $callback;
    }

    public function exists(Uuid $id): bool
    {
        return $this->store->exists($id);
    }

    public function read(Uuid $id): string|false
    {
        $data = $this->store->read($id);

        if (null !== $callback = $this->afterNextRead) {
            $this->afterNextRead = null;
            $callback();
        }

        return $data;
    }

    public function write(Uuid $id, string $data): bool
    {
        return $this->store->write($id, $data);
    }

    public function destroy(Uuid $id): bool
    {
        return $this->store->destroy($id);
    }

    public function gc(): array
    {
        return $this->store->gc();
    }
}
