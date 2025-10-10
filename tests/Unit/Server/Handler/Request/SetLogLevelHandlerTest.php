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

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\SetLogLevelHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class SetLogLevelHandlerTest extends TestCase
{
    private SetLogLevelHandler $handler;
    private ReferenceRegistryInterface&MockObject $registry;
    private LoggerInterface&MockObject $logger;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ReferenceRegistryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new SetLogLevelHandler(
            $this->registry,
            $this->logger
        );
    }

    public function testSupportsSetLogLevelRequest(): void
    {
        $request = $this->createSetLogLevelRequest(LoggingLevel::Info);

        $this->assertTrue($this->handler->supports($request));
    }

    public function testDoesNotSupportOtherRequests(): void
    {
        $otherRequest = $this->createMock(Request::class);

        $this->assertFalse($this->handler->supports($otherRequest));
    }

    public function testHandleAllLogLevelsAndSupport(): void
    {
        $logLevels = [
            LoggingLevel::Debug,
            LoggingLevel::Info,
            LoggingLevel::Notice,
            LoggingLevel::Warning,
            LoggingLevel::Error,
            LoggingLevel::Critical,
            LoggingLevel::Alert,
            LoggingLevel::Emergency,
        ];

        // Test handling all log levels
        foreach ($logLevels as $level) {
            $request = $this->createSetLogLevelRequest($level);

            $this->registry
                ->expects($this->once())
                ->method('setLoggingLevel')
                ->with($level);

            $this->logger
                ->expects($this->once())
                ->method('debug')
                ->with("Log level set to: {$level->value}");

            $response = $this->handler->handle($request, $this->session);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals($request->getId(), $response->id);
            $this->assertInstanceOf(EmptyResult::class, $response->result);

            // Verify EmptyResult serializes correctly
            $serialized = json_encode($response->result);
            $this->assertEquals('{}', $serialized);

            // Reset mocks for next iteration
            $this->setUp();
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
