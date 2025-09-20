<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\JsonRpc;

use Mcp\JsonRpc\Handler;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class HandlerTest extends TestCase
{
    #[TestDox('Make sure a single notification can be handled by multiple handlers.')]
    public function testHandleMultipleNotifications()
    {
        $handlerA = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerA->method('supports')->willReturn(true);
        $handlerA->expects($this->once())->method('handle');

        $handlerB = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerB->method('supports')->willReturn(false);
        $handlerB->expects($this->never())->method('handle');

        $handlerC = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerC->method('supports')->willReturn(true);
        $handlerC->expects($this->once())->method('handle');

        $sessionFactory = $this->createMock(SessionFactoryInterface::class);
        $sessionStore = $this->createMock(SessionStoreInterface::class);
        $session = $this->createMock(SessionInterface::class);

        $sessionFactory->method('create')->willReturn($session);
        $sessionFactory->method('createWithId')->willReturn($session);
        $sessionStore->method('exists')->willReturn(true);

        $jsonRpc = new Handler(
            MessageFactory::make(),
            $sessionFactory,
            $sessionStore,
            [$handlerA, $handlerB, $handlerC]
        );
        $sessionId = Uuid::v4();
        $result = $jsonRpc->process(
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
        iterator_to_array($result);
    }

    #[TestDox('Make sure a single request can NOT be handled by multiple handlers.')]
    public function testHandleMultipleRequests()
    {
        $handlerA = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerA->method('supports')->willReturn(true);
        $handlerA->expects($this->once())->method('handle')->willReturn(new Response(1, ['result' => 'success']));

        $handlerB = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerB->method('supports')->willReturn(false);
        $handlerB->expects($this->never())->method('handle');

        $handlerC = $this->getMockBuilder(MethodHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports', 'handle'])
            ->getMock();
        $handlerC->method('supports')->willReturn(true);
        $handlerC->expects($this->never())->method('handle');

        $sessionFactory = $this->createMock(SessionFactoryInterface::class);
        $sessionStore = $this->createMock(SessionStoreInterface::class);
        $session = $this->createMock(SessionInterface::class);

        $sessionFactory->method('create')->willReturn($session);
        $sessionFactory->method('createWithId')->willReturn($session);
        $sessionStore->method('exists')->willReturn(true);

        $jsonRpc = new Handler(
            MessageFactory::make(),
            $sessionFactory,
            $sessionStore,
            [$handlerA, $handlerB, $handlerC]
        );
        $sessionId = Uuid::v4();
        $result = $jsonRpc->process(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );
        iterator_to_array($result);
    }
}
