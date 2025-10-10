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

use Mcp\Capability\Registry\ReferenceProviderInterface;
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
    private ReferenceProviderInterface&MockObject $referenceProvider;
    private LoggerInterface&MockObject $logger;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new SetLogLevelHandler(
            $this->referenceProvider,
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

    public function testHandleValidLogLevel(): void
    {
        $request = $this->createSetLogLevelRequest(LoggingLevel::Warning);

        $this->referenceProvider
            ->expects($this->once())
            ->method('setLoggingMessageNotificationLevel')
            ->with(LoggingLevel::Warning);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Log level set to: warning');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
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

        // Test supports() method
        $testRequest = $this->createSetLogLevelRequest(LoggingLevel::Info);
        $this->assertTrue($this->handler->supports($testRequest));

        $otherRequest = $this->createMock(Request::class);
        $this->assertFalse($this->handler->supports($otherRequest));

        // Test handling all log levels
        foreach ($logLevels as $level) {
            $request = $this->createSetLogLevelRequest($level);

            $this->referenceProvider
                ->expects($this->once())
                ->method('setLoggingMessageNotificationLevel')
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

    public function testHandlerReusabilityAndStatelessness(): void
    {
        $handler1 = new SetLogLevelHandler($this->referenceProvider, $this->logger);
        $handler2 = new SetLogLevelHandler($this->referenceProvider, $this->logger);

        $request = $this->createSetLogLevelRequest(LoggingLevel::Info);

        // Both handlers should work identically
        $this->assertTrue($handler1->supports($request));
        $this->assertTrue($handler2->supports($request));

        // Test reusability with multiple requests
        $requests = [
            $this->createSetLogLevelRequest(LoggingLevel::Debug),
            $this->createSetLogLevelRequest(LoggingLevel::Error),
        ];

        // Configure mocks for multiple calls
        $this->referenceProvider
            ->expects($this->exactly(2))
            ->method('setLoggingMessageNotificationLevel');

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        foreach ($requests as $req) {
            $response = $this->handler->handle($req, $this->session);
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals($req->getId(), $response->id);
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
