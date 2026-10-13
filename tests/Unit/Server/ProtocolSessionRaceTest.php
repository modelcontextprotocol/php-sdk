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
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Tests\Unit\Server\Session\Fixture\InterleavingSessionStore;
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
        $store = new InterleavingSessionStore();
        $sessions = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $session = $sessions->createWithId($sessionId);
        $session->set('_mcp.pending_requests', ['request-7' => ['request_id' => 'request-7', 'timeout' => 120, 'timestamp' => time()]]);
        $session->save();

        $waiting = new Protocol([], [], MessageFactory::make(), $sessions);
        $answering = new Protocol([], [], MessageFactory::make(), $sessions);
        $transport = $this->createMock(TransportInterface::class);

        // The answer lands right after the waiting worker read the session,
        // before anything it does next could write the session back.
        $store->interleaveAfterNextRead(static function () use ($answering, $transport, $sessionId): void {
            $answering->processInput($transport, '{"jsonrpc": "2.0", "id": "request-7", "result": {"ok": true}}', $sessionId);
        });

        // One turn of the waiting worker's loop, with nothing queued to send.
        $this->assertSame([], $waiting->consumeOutgoingMessages($sessionId));

        $this->assertInstanceOf(Response::class, $waiting->checkResponse('request-7', $sessionId));
    }

    #[TestDox('polling a timed out request does not overwrite a client response stored meanwhile')]
    public function testPollingATimedOutRequestDoesNotOverwriteAConcurrentResponse(): void
    {
        $store = new InterleavingSessionStore();
        $sessions = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $session = $sessions->createWithId($sessionId);
        $session->set('_mcp.pending_requests', [
            'request-7' => ['request_id' => 'request-7', 'timeout' => 5, 'timestamp' => time() - 10],
            'request-8' => ['request_id' => 'request-8', 'timeout' => 120, 'timestamp' => time()],
        ]);
        $session->save();

        $waiting = new Protocol([], [], MessageFactory::make(), $sessions);
        $answering = new Protocol([], [], MessageFactory::make(), $sessions);
        $transport = $this->createMock(TransportInterface::class);

        // Another stream's answer lands while this stream polls its timed out request.
        $store->interleaveAfterNextRead(static function () use ($answering, $transport, $sessionId): void {
            $answering->processInput($transport, '{"jsonrpc": "2.0", "id": "request-8", "result": {"ok": true}}', $sessionId);
        });

        $timeout = $waiting->checkResponse('request-7', $sessionId);
        $this->assertInstanceOf(Error::class, $timeout);
        $this->assertSame('request-7', $timeout->getId());

        $this->assertInstanceOf(Response::class, $waiting->checkResponse('request-8', $sessionId));
    }

    #[TestDox('expiring a timed out request keeps a request another stream sent meanwhile')]
    public function testExpiringATimedOutRequestKeepsAConcurrentlySentRequest(): void
    {
        $store = new InterleavingSessionStore();
        $sessions = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $session = $sessions->createWithId($sessionId);
        $session->set('_mcp.pending_requests', ['request-7' => ['request_id' => 'request-7', 'timeout' => 5, 'timestamp' => time() - 10]]);
        $session->save();

        $waiting = new Protocol([], [], MessageFactory::make(), $sessions);

        // Another stream sends a request right after the expiry reloaded the session, the poll's own read comes first.
        $store->interleaveAfterNextRead(static function () use ($store, $sessions, $sessionId): void {
            $store->interleaveAfterNextRead(static function () use ($sessions, $sessionId): void {
                $sending = $sessions->createWithId($sessionId);
                $pending = $sending->get('_mcp.pending_requests', []);
                $pending['request-9'] = ['request_id' => 'request-9', 'timeout' => 120, 'timestamp' => time()];
                $sending->set('_mcp.pending_requests', $pending);
                $sending->save();
            });
        });

        $this->assertInstanceOf(Error::class, $waiting->checkResponse('request-7', $sessionId));

        $this->assertSame(['request-9'], array_keys($sessions->createWithId($sessionId)->get('_mcp.pending_requests')));
    }
}
