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
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\Tool\CallToolExceptionEvent;
use Mcp\Event\Tool\CallToolRequestEvent;
use Mcp\Event\Tool\CallToolResultEvent;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class CallToolHandlerTest extends TestCase
{
    private CallToolHandler $handler;
    private RegistryInterface&MockObject $registry;
    private ReferenceHandlerInterface&MockObject $referenceHandler;
    private LoggerInterface&MockObject $logger;
    private SessionInterface&MockObject $session;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    /** @var array<CallToolExceptionEvent|CallToolRequestEvent|CallToolResultEvent> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Store all dispatched events for further assertion
        $this->eventDispatcher
            ->method('dispatch')
            ->with($this->callback(function (CallToolExceptionEvent|CallToolRequestEvent|CallToolResultEvent $event) {
                $this->dispatchedEvents[] = $event;

                return true;
            }));

        $this->handler = new CallToolHandler(
            $this->registry,
            $this->referenceHandler,
            $this->logger,
            $this->eventDispatcher,
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
        $toolReference = $this->createMock(ToolReference::class);
        $expectedResult = new CallToolResult([new TextContent('Hello, John!')]);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('greet_user')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['name' => 'John', '_session' => $this->session, '_request' => $request])
            ->willReturn('Hello, John!');

        $resultContent = [new TextContent('Hello, John!')];

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Hello, John!')
            ->willReturn($resultContent);

        // Logger may be called for debugging, so we don't assert never()

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertSame($resultContent, $callToolResultEvent->getResult()->content);
    }

    public function testHandleToolCallWithEmptyArguments(): void
    {
        $request = $this->createCallToolRequest('simple_tool', []);
        $toolReference = $this->createMock(ToolReference::class);
        $expectedResult = new CallToolResult([new TextContent('Simple result')]);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('simple_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['_session' => $this->session, '_request' => $request])
            ->willReturn('Simple result');

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Simple result')
            ->willReturn([new TextContent('Simple result')]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertEquals($expectedResult, $callToolResultEvent->getResult());
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
        $toolReference = $this->createMock(ToolReference::class);
        $expectedResult = new CallToolResult([new TextContent('Complex result')]);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('complex_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, array_merge($arguments, ['_session' => $this->session, '_request' => $request]))
            ->willReturn('Complex result');

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Complex result')
            ->willReturn([new TextContent('Complex result')]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertEquals($expectedResult, $callToolResultEvent->getResult());
    }

    public function testHandleToolNotFoundExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('nonexistent_tool', ['param' => 'value']);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('nonexistent_tool')
            ->willThrowException(new ToolNotFoundException('nonexistent_tool'));

        $this->logger
            ->expects($this->once())
            ->method('error');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::METHOD_NOT_FOUND, $response->code);

        $this->assertCount(1, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());
    }

    public function testHandleToolCallExceptionReturnsResponseWithErrorResult(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new ToolCallException('Tool execution failed');

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('failing_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['param' => 'value', '_session' => $this->session, '_request' => $request])
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);

        $result = $response->result;
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('Tool execution failed', $result->content[0]->text);

        $this->assertCount(1, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());
    }

    public function testHandleWithNullResult(): void
    {
        $request = $this->createCallToolRequest('null_tool', []);
        $expectedResult = new CallToolResult([]);

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('null_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['_session' => $this->session, '_request' => $request])
            ->willReturn(null);

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with(null)
            ->willReturn([]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertSame([], $callToolResultEvent->getResult()->content);
    }

    public function testConstructorWithDefaultLogger(): void
    {
        $handler = new CallToolHandler($this->registry, $this->referenceHandler, eventDispatcher: $this->createMock(EventDispatcherInterface::class));

        $this->assertInstanceOf(CallToolHandler::class, $handler);
    }

    public function testConstructorWithoutEventDispatched(): void
    {
        $handler = new CallToolHandler($this->registry, $this->referenceHandler, eventDispatcher: null);

        $this->assertInstanceOf(CallToolHandler::class, $handler);
    }

    public function testHandleLogsErrorWithCorrectParameters(): void
    {
        $request = $this->createCallToolRequest('test_tool', ['key1' => 'value1', 'key2' => 42]);
        $exception = new ToolCallException('Custom error message');

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('test_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['key1' => 'value1', 'key2' => 42, '_session' => $this->session, '_request' => $request])
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while executing tool "test_tool": "Custom error message".',
                [
                    'tool' => 'test_tool',
                    'arguments' => ['key1' => 'value1', 'key2' => 42, '_session' => $this->session, '_request' => $request],
                ],
            );

        $response = $this->handler->handle($request, $this->session);

        // ToolCallException should now return Response with CallToolResult having isError=true
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);

        $result = $response->result;
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('Custom error message', $result->content[0]->text);

        $this->assertCount(1, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());
    }

    public function testHandleGenericExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new \RuntimeException('Internal database connection failed');

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('failing_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['param' => 'value', '_session' => $this->session, '_request' => $request])
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        // Generic exceptions should return Error, not Response
        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while executing tool', $response->message);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolExceptionEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertSame($exception, $callToolResultEvent->getThrowable());
    }

    public function testHandleWithSpecialCharactersInToolName(): void
    {
        $request = $this->createCallToolRequest('tool-with_special.chars', []);
        $expectedResult = new CallToolResult([new TextContent('Special tool result')]);

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('tool-with_special.chars')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['_session' => $this->session, '_request' => $request])
            ->willReturn('Special tool result');

        $content = [new TextContent('Special tool result')];

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Special tool result')
            ->willReturn($content);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertSame($content, $callToolResultEvent->getResult()->content);
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

        $toolReference = $this->createMock(ToolReference::class);
        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('unicode_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, array_merge($arguments, ['_session' => $this->session, '_request' => $request]))
            ->willReturn('Unicode handled');

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Unicode handled')
            ->willReturn([new TextContent('Unicode handled')]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertEquals($expectedResult, $callToolResultEvent->getResult());
    }

    public function testHandleReturnsStructuredContentResult(): void
    {
        $request = $this->createCallToolRequest('structured_tool', ['query' => 'php']);
        $toolReference = $this->createMock(ToolReference::class);
        $structuredResult = new CallToolResult([new TextContent('Rendered results')], false, ['result' => 'Rendered results']);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('structured_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['query' => 'php', '_session' => $this->session, '_request' => $request])
            ->willReturn($structuredResult);

        $toolReference
            ->expects($this->never())
            ->method('formatResult');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($structuredResult, $response->result);
        $this->assertEquals(['result' => 'Rendered results'], $response->result->jsonSerialize()['structuredContent'] ?? []);

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertEquals($structuredResult, $callToolResultEvent->getResult());
    }

    public function testHandleReturnsCallToolResult(): void
    {
        $request = $this->createCallToolRequest('result_tool', ['query' => 'php']);
        $toolReference = $this->createMock(ToolReference::class);
        $callToolResult = new CallToolResult([new TextContent('Error result')], true);

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('result_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['query' => 'php', '_session' => $this->session, '_request' => $request])
            ->willReturn($callToolResult);

        $toolReference
            ->expects($this->never())
            ->method('formatResult');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($callToolResult, $response->result);
        $this->assertArrayNotHasKey('structuredContent', $response->result->jsonSerialize());

        $this->assertCount(2, $this->dispatchedEvents);

        $callToolRequestEvent = $this->dispatchedEvents[0];
        $this->assertInstanceOf(CallToolRequestEvent::class, $callToolRequestEvent);
        $this->assertSame($request, $callToolRequestEvent->getRequest());

        $callToolResultEvent = $this->dispatchedEvents[1];
        $this->assertInstanceOf(CallToolResultEvent::class, $callToolResultEvent);
        $this->assertSame($request, $callToolResultEvent->getRequest());
        $this->assertEquals($callToolResult, $callToolResultEvent->getResult());
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
