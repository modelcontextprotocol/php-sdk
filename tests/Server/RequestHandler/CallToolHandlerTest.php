<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Server\RequestHandler;

use Mcp\Capability\Tool\ToolExecutorInterface;
use Mcp\Exception\ToolExecutionException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\RequestHandler\CallToolHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallToolHandlerTest extends TestCase
{
    private CallToolHandler $handler;
    private ToolExecutorInterface|MockObject $toolExecutor;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->toolExecutor = $this->createMock(ToolExecutorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new CallToolHandler(
            $this->toolExecutor,
            $this->logger,
        );
    }

    public function testSupportsCallToolRequest(): void
    {
        $request = $this->createCallToolRequest('test_tool', ['param' => 'value']);

        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandleSuccessfulToolCall(): void
    {
        $request = $this->createCallToolRequest('greet_user', ['name' => 'John']);
        $expectedResult = new CallToolResult([new TextContent('Hello, John!')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $this->logger
            ->expects($this->never())
            ->method('error');

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandleToolCallWithEmptyArguments(): void
    {
        $request = $this->createCallToolRequest('simple_tool', []);
        $expectedResult = new CallToolResult([new TextContent('Simple result')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandleToolCallWithComplexArguments(): void
    {
        $arguments = [
            'string_param' => 'value',
            'int_param' => 42,
            'bool_param' => true,
            'array_param' => ['nested' => 'data'],
            'null_param' => null,
        ];
        $request = $this->createCallToolRequest('complex_tool', $arguments);
        $expectedResult = new CallToolResult([new TextContent('Complex result')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandleToolNotFoundExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('nonexistent_tool', ['param' => 'value']);
        $exception = new ToolNotFoundException($request);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while executing tool "nonexistent_tool": "Tool not found for call: "nonexistent_tool".".',
                [
                    'tool' => 'nonexistent_tool',
                    'arguments' => ['param' => 'value'],
                ],
            );

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while executing tool', $response->message);
    }

    public function testHandleToolExecutionExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new ToolExecutionException($request, new \RuntimeException('Tool execution failed'));

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while executing tool "failing_tool": "Execution of tool "failing_tool" failed with error: "Tool execution failed".".',
                [
                    'tool' => 'failing_tool',
                    'arguments' => ['param' => 'value'],
                ],
            );

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while executing tool', $response->message);
    }

    public function testHandleWithNullResult(): void
    {
        $request = $this->createCallToolRequest('null_tool', []);
        $expectedResult = new CallToolResult([]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandleWithErrorResult(): void
    {
        $request = $this->createCallToolRequest('error_tool', []);
        $expectedResult = CallToolResult::error([new TextContent('Tool error occurred')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertTrue($response->result->isError);
    }

    public function testConstructorWithDefaultLogger(): void
    {
        $handler = new CallToolHandler($this->toolExecutor);

        $this->assertInstanceOf(CallToolHandler::class, $handler);
    }

    public function testHandleLogsErrorWithCorrectParameters(): void
    {
        $request = $this->createCallToolRequest('test_tool', ['key1' => 'value1', 'key2' => 42]);
        $exception = new ToolExecutionException($request, new \RuntimeException('Custom error message'));

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while executing tool "test_tool": "Execution of tool "test_tool" failed with error: "Custom error message".".',
                [
                    'tool' => 'test_tool',
                    'arguments' => ['key1' => 'value1', 'key2' => 42],
                ],
            );

        $this->handler->handle($request);
    }

    public function testHandleWithSpecialCharactersInToolName(): void
    {
        $request = $this->createCallToolRequest('tool-with_special.chars', []);
        $expectedResult = new CallToolResult([new TextContent('Special tool result')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandleWithSpecialCharactersInArguments(): void
    {
        $arguments = [
            'special_chars' => 'äöü ñ 中文 🚀',
            'unicode' => '\\u{1F600}',
            'quotes' => 'text with "quotes" and \'single quotes\'',
        ];
        $request = $this->createCallToolRequest('unicode_tool', $arguments);
        $expectedResult = new CallToolResult([new TextContent('Unicode handled')]);

        $this->toolExecutor
            ->expects($this->once())
            ->method('call')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    private function createCallToolRequest(string $name, array $arguments): Request
    {
        return CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => CallToolRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }
}
