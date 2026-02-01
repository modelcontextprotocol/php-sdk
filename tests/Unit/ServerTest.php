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
            ->willReturnCallback(static function () use (&$callOrder) {
                $callOrder[] = 'initialize';
            });

        $this->protocol->expects($this->once())
            ->method('connect')
            ->with($this->transport)
            ->willReturnCallback(static function () use (&$callOrder) {
                $callOrder[] = 'connect';
            });

        $this->transport->expects($this->once())
            ->method('listen')
            ->willReturnCallback(static function () use (&$callOrder) {
                $callOrder[] = 'listen';

                return 0;
            });

        $this->transport->expects($this->once())
            ->method('close')
            ->willReturnCallback(static function () use (&$callOrder) {
                $callOrder[] = 'close';
            });

        $server = new Server($this->protocol);
        $result = $server->run($this->transport);

        $this->assertEquals([
            'initialize',
            'connect',
            'listen',
            'close',
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
}
