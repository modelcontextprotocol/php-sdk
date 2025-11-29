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

use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Protocol;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    /** @var MockObject&Protocol */
    private $protocol;

    /** @var MockObject&TransportInterface<int> */
    private $transport;

    protected function setUp(): void
    {
        $this->protocol = $this->createMock(Protocol::class);
        $this->transport = $this->createMock(TransportInterface::class);
    }

    #[TestDox('builder() returns a Builder instance')]
    public function testBuilderReturnsBuilderInstance(): void
    {
        $builder = Server::builder();

        $this->assertInstanceOf(Builder::class, $builder);
    }

    #[TestDox('run() orchestrates transport lifecycle and protocol connection')]
    public function testRunOrchestatesTransportLifecycle(): void
    {
        $callOrder = [];

        $this->transport->expects($this->once())
            ->method('initialize')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'initialize';
            });

        $this->protocol->expects($this->once())
            ->method('connect')
            ->with($this->transport)
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'connect';
            });

        $this->transport->expects($this->once())
            ->method('listen')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'listen';

                return 0;
            });

        $this->transport->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'close';
            });

        $this->protocol->expects($this->once())
            ->method('disconnect')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'disconnect';
            });

        $server = new Server($this->protocol);
        $result = $server->run($this->transport);

        $this->assertEquals([
            'initialize',
            'connect',
            'listen',
            'close',
            'disconnect',
        ], $callOrder);

        $this->assertEquals(0, $result);
    }

    #[TestDox('run() closes transport even if listen() throws exception')]
    public function testRunClosesTransportEvenOnException(): void
    {
        $this->transport->method('initialize');
        $this->protocol->method('connect');

        $this->transport->expects($this->once())
            ->method('listen')
            ->willThrowException(new \RuntimeException('Transport error'));

        // close() should still be called even though listen() threw
        $this->transport->expects($this->once())->method('close');

        $server = new Server($this->protocol);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transport error');

        $server->run($this->transport);
    }

    #[TestDox('run() propagates exception if initialize() throws')]
    public function testRunPropagatesInitializeException(): void
    {
        $this->transport->expects($this->once())
            ->method('initialize')
            ->willThrowException(new \RuntimeException('Initialize error'));

        $server = new Server($this->protocol);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Initialize error');

        $server->run($this->transport);
    }

    #[TestDox('run() returns value from transport.listen()')]
    public function testRunReturnsTransportListenValue(): void
    {
        $this->transport->method('initialize');
        $this->protocol->method('connect');
        $this->transport->method('close');

        $expectedReturn = 42;
        $this->transport->expects($this->once())
            ->method('listen')
            ->willReturn($expectedReturn);

        $server = new Server($this->protocol);
        $result = $server->run($this->transport);

        $this->assertEquals($expectedReturn, $result);
    }

    #[TestDox('run() connects protocol to transport')]
    public function testRunConnectsProtocolToTransport(): void
    {
        $this->transport->method('initialize');
        $this->transport->method('listen')->willReturn(0);
        $this->transport->method('close');

        $this->protocol->expects($this->once())
            ->method('connect')
            ->with($this->identicalTo($this->transport));

        $server = new Server($this->protocol);
        $server->run($this->transport);
    }

    #[TestDox('run() disconnects protocol after completion (worker mode support)')]
    public function testRunDisconnectsProtocolAfterCompletion(): void
    {
        $this->transport->method('initialize');
        $this->transport->method('listen')->willReturn(0);
        $this->transport->method('close');

        $this->protocol->expects($this->once())->method('connect');
        $this->protocol->expects($this->once())->method('disconnect');

        $server = new Server($this->protocol);
        $server->run($this->transport);
    }

    #[TestDox('run() disconnects protocol even if listen() throws (worker mode support)')]
    public function testRunDisconnectsProtocolEvenOnException(): void
    {
        $this->transport->method('initialize');
        $this->protocol->method('connect');

        $this->transport->expects($this->once())
            ->method('listen')
            ->willThrowException(new \RuntimeException('Transport error'));

        $this->transport->expects($this->once())->method('close');
        $this->protocol->expects($this->once())->method('disconnect');

        $server = new Server($this->protocol);

        $this->expectException(\RuntimeException::class);
        $server->run($this->transport);
    }

    #[TestDox('run() can be called multiple times with different transports (worker mode)')]
    public function testRunCanBeCalledMultipleTimes(): void
    {
        $callOrder = [];

        $transport1 = $this->createMock(TransportInterface::class);
        $transport2 = $this->createMock(TransportInterface::class);

        $transport1->method('initialize');
        $transport1->method('listen')->willReturn(1);
        $transport1->method('close');

        $transport2->method('initialize');
        $transport2->method('listen')->willReturn(2);
        $transport2->method('close');

        // Use a real-ish protocol behavior simulation
        $this->protocol->expects($this->exactly(2))
            ->method('connect')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'connect';
            });

        $this->protocol->expects($this->exactly(2))
            ->method('disconnect')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'disconnect';
            });

        $server = new Server($this->protocol);

        $this->assertEquals(1, $server->run($transport1));
        $this->assertEquals(2, $server->run($transport2));

        $this->assertEquals(['connect', 'disconnect', 'connect', 'disconnect'], $callOrder);
    }
}
