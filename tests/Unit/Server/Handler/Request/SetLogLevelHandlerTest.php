<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request;

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\SetLogLevelHandler;
use Mcp\Server\Protocol;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class SetLogLevelHandlerTest extends TestCase
{
    public function testSupports(): void
    {
        $request = $this->createSetLogLevelRequest(LoggingLevel::Info);
        $handler = new SetLogLevelHandler();
        $this->assertTrue($handler->supports($request));
    }

    public function testDoesNotSupportOtherRequests(): void
    {
        $otherRequest = $this->createMock(Request::class);
        $handler = new SetLogLevelHandler();
        $this->assertFalse($handler->supports($otherRequest));
    }

    public function testHandleAllLogLevelsAndSupport(): void
    {
        $handler = new SetLogLevelHandler();

        foreach (LoggingLevel::cases() as $level) {
            $request = $this->createSetLogLevelRequest($level);

            $session = $this->getMockBuilder(Session::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['set'])
                ->getMock();
            $session->expects($this->once())
                ->method('set')
                ->with(Protocol::SESSION_LOGGING_LEVEL, $level->value);

            $response = $handler->handle($request, $session);
            $this->assertEquals($request->getId(), $response->id);
            $this->assertInstanceOf(EmptyResult::class, $response->result);
        }
    }

    private function createSetLogLevelRequest(LoggingLevel $level): SetLogLevelRequest
    {
        return SetLogLevelRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => SetLogLevelRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'level' => $level->value,
            ],
        ]);
    }
}
