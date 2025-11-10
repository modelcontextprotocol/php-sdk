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

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallToolHandlerTest extends TestCase
{
    private CallToolHandler $handler;
    private ReferenceProviderInterface|MockObject $referenceProvider;
    private ReferenceHandlerInterface|MockObject $referenceHandler;
    private LoggerInterface|MockObject $logger;
    private SessionInterface|MockObject $session;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new CallToolHandler(
            $this->referenceProvider,
            $this->referenceHandler,
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
        $tool = new Tool('greet_user', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return 'Hello, John!';
        });
        $expectedResult = new CallToolResult([new TextContent('Hello, John!')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('greet_user')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['name' => 'John'])
            ->willReturn('Hello, John!');

        $this->logger
            ->expects($this->never())
            ->method('error');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleToolCallWithEmptyArguments(): void
    {
        $request = $this->createCallToolRequest('simple_tool', []);
        $tool = new Tool('simple_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return 'Simple result';
        });
        $expectedResult = new CallToolResult([new TextContent('Simple result')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('simple_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn('Simple result');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
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
        $tool = new Tool('complex_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return 'Complex result';
        });
        $expectedResult = new CallToolResult([new TextContent('Complex result')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('complex_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, $arguments)
            ->willReturn('Complex result');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleToolNotFoundExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('nonexistent_tool', ['param' => 'value']);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('nonexistent_tool')
            ->willThrowException(new ToolNotFoundException($request));

        $this->logger
            ->expects($this->once())
            ->method('error');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::METHOD_NOT_FOUND, $response->code);
    }

    public function testHandleToolExecutionExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new ToolCallException($request, new \RuntimeException('Tool execution failed'));

        $toolReference = $this->createMock(ToolReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('failing_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['param' => 'value'])
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
    }

    public function testHandleWithNullResult(): void
    {
        $request = $this->createCallToolRequest('null_tool', []);
        $tool = new Tool('null_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return null;
        });
        $expectedResult = new CallToolResult([new TextContent('(null)')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('null_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn(null);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testConstructorWithDefaultLogger(): void
    {
        $handler = new CallToolHandler($this->referenceProvider, $this->referenceHandler);

        $this->assertInstanceOf(CallToolHandler::class, $handler);
    }

    public function testHandleLogsErrorWithCorrectParameters(): void
    {
        $request = $this->createCallToolRequest('test_tool', ['key1' => 'value1', 'key2' => 42]);
        $exception = new ToolCallException($request, new \RuntimeException('Custom error message'));

        $toolReference = $this->createMock(ToolReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('test_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['key1' => 'value1', 'key2' => 42])
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while executing tool "test_tool": "Tool call "test_tool" failed with error: "Custom error message".".',
                [
                    'tool' => 'test_tool',
                    'arguments' => ['key1' => 'value1', 'key2' => 42],
                ],
            );

        $this->handler->handle($request, $this->session);
    }

    public function testHandleWithSpecialCharactersInToolName(): void
    {
        $request = $this->createCallToolRequest('tool-with_special.chars', []);
        $tool = new Tool('tool-with_special.chars', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return 'Special tool result';
        });
        $expectedResult = new CallToolResult([new TextContent('Special tool result')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('tool-with_special.chars')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn('Special tool result');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleWithSpecialCharactersInArguments(): void
    {
        $arguments = [
            'special_chars' => 'äöü ñ 中文 🚀',
            'unicode' => '\\u{1F600}',
            'quotes' => 'text with "quotes" and \'single quotes\'',
        ];
        $request = $this->createCallToolRequest('unicode_tool', $arguments);
        $tool = new Tool('unicode_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null, null);
        $toolReference = new ToolReference($tool, function () {
            return 'Unicode handled';
        });
        $expectedResult = new CallToolResult([new TextContent('Unicode handled')]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('unicode_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, $arguments)
            ->willReturn('Unicode handled');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createCallToolRequest(string $name, array $arguments): CallToolRequest
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
