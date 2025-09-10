<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\JsonRpc;

use Mcp\JsonRpc\Handler;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\MethodHandlerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    #[TestDox('Make sure a single notification can be handled by multiple handlers.')]
    public function testHandleMultipleNotifications(): void
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

        $jsonRpc = new Handler(MessageFactory::make(), [$handlerA, $handlerB, $handlerC]);
        $result = $jsonRpc->process(
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}'
        );
        iterator_to_array($result);
    }

    #[TestDox('Make sure a single request can NOT be handled by multiple handlers.')]
    public function testHandleMultipleRequests(): void
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

        $jsonRpc = new Handler(MessageFactory::make(), [$handlerA, $handlerB, $handlerC]);
        $result = $jsonRpc->process(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}'
        );
        iterator_to_array($result);
    }
}
