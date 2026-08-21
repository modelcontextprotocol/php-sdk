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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class CallToolHandlerTest extends TestCase
{
    private CallToolHandler $handler;
    private RegistryInterface&MockObject $registry;
    private ReferenceHandlerInterface&MockObject $referenceHandler;
    private LoggerInterface&MockObject $logger;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new CallToolHandler(
            $this->registry,
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
        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Hello, John!')
            ->willReturn([new TextContent('Hello, John!')]);

        // Logger may be called for debugging, so we don't assert never()

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleToolCallWithEmptyArguments(): void
    {
        $request = $this->createCallToolRequest('simple_tool', []);
        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
    }

    public function testHandleToolNotFoundExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('nonexistent_tool', ['param' => 'value']);
        $exception = new ToolNotFoundException('nonexistent_tool');

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('nonexistent_tool')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Tool not found', ['name' => 'nonexistent_tool', 'exception' => $exception]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INVALID_PARAMS, $response->code);
    }

    public function testHandleToolCallExceptionReturnsResponseWithErrorResult(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new ToolCallException('Tool execution failed');

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
            ->expects($this->atLeastOnce())
            ->method('debug');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);

        $result = $response->result;
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('Tool execution failed', $result->content[0]->text);
    }

    public function testHandleToolCallExceptionLogsAtDebugLevel(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new ToolCallException('Expected tool failure');
        $logger = new class extends AbstractLogger {
            public array $records = [];

            // @phpstan-ignore missingType.parameter (compatible with psr/log 1.x)
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $handler = new CallToolHandler($this->registry, $this->referenceHandler, $logger);
        $toolReference = $this->createToolReference('failing_tool', static function () {
            return 'unused';
        });

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('failing_tool')
            ->willReturn($toolReference);

        $arguments = ['param' => 'value', '_session' => $this->session, '_request' => $request];
        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, $arguments)
            ->willThrowException($exception);

        $handler->handle($request, $this->session);

        $failureRecords = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => str_contains($record['message'], 'Expected tool failure'),
        ));

        $this->assertSame([
            [
                'level' => 'debug',
                'message' => 'Error while executing tool "failing_tool": "Expected tool failure".',
                'context' => [
                    'tool' => 'failing_tool',
                    'arguments' => $arguments,
                    'exception' => $exception,
                ],
            ],
        ], $failureRecords);
        $this->assertSame([], array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => \in_array($record['level'], ['error', 'critical'], true),
        )));
    }

    public function testHandleWithNullResult(): void
    {
        $request = $this->createCallToolRequest('null_tool', []);
        $expectedResult = new CallToolResult([]);

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
    }

    public function testConstructorWithDefaultLogger(): void
    {
        $handler = new CallToolHandler($this->registry, $this->referenceHandler);

        $this->assertInstanceOf(CallToolHandler::class, $handler);
    }

    public function testHandleLogsToolCallException(): void
    {
        $request = $this->createCallToolRequest('test_tool', ['key1' => 'value1', 'key2' => 42]);
        $exception = new ToolCallException('Custom error message');

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
            ->expects($this->atLeastOnce())
            ->method('debug');

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
    }

    public function testHandleGenericExceptionReturnsError(): void
    {
        $request = $this->createCallToolRequest('failing_tool', ['param' => 'value']);
        $exception = new \RuntimeException('Internal database connection failed');

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
            ->method('error')
            ->with('Unhandled error during tool execution', [
                'name' => 'failing_tool',
                'exception' => $exception,
            ]);

        $response = $this->handler->handle($request, $this->session);

        // Generic exceptions should return Error, not Response
        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while executing tool', $response->message);
    }

    public function testHandleWithSpecialCharactersInToolName(): void
    {
        $request = $this->createCallToolRequest('tool-with_special.chars', []);
        $expectedResult = new CallToolResult([new TextContent('Special tool result')]);

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });

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

        $toolReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('Special tool result')
            ->willReturn([new TextContent('Special tool result')]);

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
        $expectedResult = new CallToolResult([new TextContent('Unicode handled')]);

        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
    }

    public function testHandleReturnsStructuredContentResult(): void
    {
        $request = $this->createCallToolRequest('structured_tool', ['query' => 'php']);
        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
    }

    public function testHandleReturnsCallToolResult(): void
    {
        $request = $this->createCallToolRequest('result_tool', ['query' => 'php']);
        $toolReference = $this->createToolReference('greet_user', static function () {
            return 'Hello, John!';
        });
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
    }

    /**
     * @dataProvider provideStructuredContentRevisions
     */
    public function testStructuredContentFollowsTheNegotiatedRevision(?string $negotiated, ?array $expected): void
    {
        $listResult = [['id' => 1], ['id' => 2]];
        $request = $this->createCallToolRequest('list_things', []);
        $toolReference = $this->createToolReference('list_things', static fn () => $listResult);

        $this->session
            ->method('get')
            ->with('protocol_version')
            ->willReturn($negotiated);

        $this->registry
            ->method('getTool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->method('handle')
            ->willReturn($listResult);

        $toolReference
            ->method('formatResult')
            ->willReturn([new TextContent('[{"id":1},{"id":2}]')]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expected, $response->result->structuredContent);
    }

    /**
     * How a revision is resolved is {@see \Mcp\Server\RequestContext}'s business
     * and covered there; this only pins that the handler applies it.
     *
     * @return iterable<string, array{?string, ?array<mixed>}>
     */
    public static function provideStructuredContentRevisions(): iterable
    {
        // A list is only emittable from 2026-07-28 (SEP-2106) on. Without a
        // negotiated revision the handler assumes the stricter handshake rule.
        yield 'no negotiated revision' => [null, null];
        yield '2025-11-25' => ['2025-11-25', null];
        yield '2026-07-28' => ['2026-07-28', [['id' => 1], ['id' => 2]]];
    }

    /**
     * @dataProvider provideSelfBuiltResults
     */
    public function testSelfBuiltResultIsSentUnchangedAndOnlyWarnedAbout(
        ?string $negotiated,
        ?array $structuredContent,
        int $expectedWarnings,
    ): void {
        $request = $this->createCallToolRequest('build_result', []);
        $toolReference = $this->createToolReference('build_result', static fn () => null);
        $callToolResult = new CallToolResult([new TextContent('Built by hand')], false, $structuredContent);

        $this->session
            ->method('get')
            ->with('protocol_version')
            ->willReturn($negotiated);

        $this->registry
            ->method('getTool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->method('handle')
            ->willReturn($callToolResult);

        $toolReference
            ->expects($this->never())
            ->method('formatResult');

        $this->logger
            ->expects($this->exactly($expectedWarnings))
            ->method('warning');

        $response = $this->handler->handle($request, $this->session);

        // Warned about, never rewritten: building the result is an explicit opt-out.
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($callToolResult, $response->result);
        $this->assertSame($structuredContent, $response->result->structuredContent);
    }

    /**
     * @return iterable<string, array{?string, ?array<mixed>, int}>
     */
    public static function provideSelfBuiltResults(): iterable
    {
        yield 'list before SEP-2106' => ['2025-11-25', [['id' => 1]], 1];
        yield 'list without a negotiated revision' => [null, [['id' => 1]], 1];
        yield 'list from SEP-2106 on' => ['2026-07-28', [['id' => 1]], 0];
        yield 'object before SEP-2106' => ['2025-11-25', ['items' => [['id' => 1]]], 0];
        yield 'none at all' => ['2025-11-25', null, 0];
        // Dropped by `CallToolResult::jsonSerialize()` anyway, so nothing to warn about.
        yield 'empty' => ['2025-11-25', [], 0];
    }

    public function testDeclaredOutputSchemaWithoutStructuredContentIsLogged(): void
    {
        $listResult = [['id' => 1]];
        $request = $this->createCallToolRequest('list_things', []);
        $toolReference = $this->createToolReference('list_things', static fn () => $listResult, [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array']],
        ]);

        $this->registry
            ->method('getTool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->method('handle')
            ->willReturn($listResult);

        $toolReference
            ->method('formatResult')
            ->willReturn([new TextContent('[{"id":1}]')]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('outputSchema'),
                $this->callback(static fn (array $context): bool => 'list_things' === $context['name'] && 'array' === $context['result_type']),
            );

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->result->structuredContent);
    }

    public function testValidationError(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'favorite_number' => [
                    'type' => 'number',
                    'description' => 'Your favorite number',
                ],
            ],
            'required' => [
                'favorite_number',
            ],
        ];

        $request = $this->createCallToolRequest('result_tool', ['query' => 'php']);
        $toolReference = $this->getMockBuilder(ToolReference::class)
            ->setConstructorArgs([new Tool('simple_tool', null, $schema, null, null), static function () {}])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getTool')
            ->with('result_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->never())
            ->method('handle');

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INVALID_PARAMS, $response->code);
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

    private function createToolReference(
        string $name,
        callable $handler,
        ?array $outputSchema = null,
        array $methodsToMock = ['formatResult'],
    ): ToolReference&MockObject {
        $schema = [
            'type' => 'object',
            'properties' => [
                'example' => [
                    'type' => 'string',
                    'description' => 'This is just a dummy',
                ],
            ],
            'required' => [],
        ];
        $tool = new Tool($name, null, $schema, null, null, null, null, $outputSchema);

        $builder = $this->getMockBuilder(ToolReference::class)
            ->setConstructorArgs([$tool, $handler]);

        if (!empty($methodsToMock)) {
            $builder->onlyMethods($methodsToMock);
        }

        return $builder->getMock();
    }
}
