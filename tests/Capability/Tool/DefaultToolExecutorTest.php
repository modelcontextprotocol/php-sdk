<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Tool;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\Tool\DefaultToolExecutor;
use Mcp\Exception\ToolExecutionException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DefaultToolExecutorTest extends TestCase
{
    private DefaultToolExecutor $toolExecutor;
    private ReferenceProviderInterface $referenceProvider;
    private ReferenceHandlerInterface $referenceHandler;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->toolExecutor = new DefaultToolExecutor(
            $this->referenceProvider,
            $this->referenceHandler,
            $this->logger,
        );
    }

    public function testCallExecutesToolSuccessfully(): void
    {
        $request = new CallToolRequest('test_tool', ['param' => 'value']);
        $tool = $this->createValidTool('test_tool');
        $toolReference = new ToolReference($tool, fn () => 'test result');
        $handlerResult = 'test result';

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('test_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['param' => 'value'])
            ->willReturn($handlerResult);

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug')
            ->with(
                $this->logicalOr(
                    $this->equalTo('Executing tool'),
                    $this->equalTo('Tool executed successfully')
                ),
                $this->logicalOr(
                    $this->equalTo(['name' => 'test_tool', 'arguments' => ['param' => 'value']]),
                    $this->equalTo(['name' => 'test_tool', 'result_type' => 'string'])
                )
            );

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('test result', $result->content[0]->text);
        $this->assertFalse($result->isError);
    }

    public function testCallWithEmptyArguments(): void
    {
        $request = new CallToolRequest('test_tool', []);
        $tool = $this->createValidTool('test_tool');
        $toolReference = new ToolReference($tool, fn () => 'result');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('test_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn('result');

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
    }

    public function testCallWithComplexArguments(): void
    {
        $arguments = [
            'string_param' => 'value',
            'int_param' => 42,
            'bool_param' => true,
            'array_param' => ['nested' => 'data'],
            'null_param' => null,
        ];
        $request = new CallToolRequest('complex_tool', $arguments);
        $tool = $this->createValidTool('complex_tool');
        $toolReference = new ToolReference($tool, fn () => ['processed' => true]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('complex_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, $arguments)
            ->willReturn(['processed' => true]);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
    }

    public function testCallThrowsToolNotFoundExceptionWhenToolNotFound(): void
    {
        $request = new CallToolRequest('nonexistent_tool', ['param' => 'value']);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('nonexistent_tool')
            ->willReturn(null);

        $this->referenceHandler
            ->expects($this->never())
            ->method('handle');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Executing tool', ['name' => 'nonexistent_tool', 'arguments' => ['param' => 'value']]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Tool not found', ['name' => 'nonexistent_tool']);

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessage('Tool not found for call: "nonexistent_tool".');

        $this->toolExecutor->call($request);
    }

    public function testCallThrowsToolExecutionExceptionWhenHandlerThrowsException(): void
    {
        $request = new CallToolRequest('failing_tool', ['param' => 'value']);
        $tool = $this->createValidTool('failing_tool');
        $toolReference = new ToolReference($tool, fn () => throw new \RuntimeException('Handler failed'));
        $handlerException = new \RuntimeException('Handler failed');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('failing_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, ['param' => 'value'])
            ->willThrowException($handlerException);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Executing tool', ['name' => 'failing_tool', 'arguments' => ['param' => 'value']]);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Tool execution failed',
                $this->callback(function ($context) {
                    return 'failing_tool' === $context['name']
                        && 'Handler failed' === $context['exception']
                        && isset($context['trace']);
                })
            );

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Execution of tool "failing_tool" failed with error: "Handler failed".');

        $thrownException = null;
        try {
            $this->toolExecutor->call($request);
        } catch (ToolExecutionException $e) {
            $thrownException = $e;
            throw $e;
        } finally {
            if ($thrownException) {
                $this->assertSame($request, $thrownException->request);
                $this->assertSame($handlerException, $thrownException->getPrevious());
            }
        }
    }

    public function testCallHandlesNullResult(): void
    {
        $request = new CallToolRequest('null_tool', []);
        $tool = $this->createValidTool('null_tool');
        $toolReference = new ToolReference($tool, fn () => null);

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

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('(null)', $result->content[0]->text);
    }

    public function testCallHandlesBooleanResults(): void
    {
        $request = new CallToolRequest('bool_tool', []);
        $tool = $this->createValidTool('bool_tool');
        $toolReference = new ToolReference($tool, fn () => true);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('bool_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn(true);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('true', $result->content[0]->text);
    }

    public function testCallHandlesArrayResults(): void
    {
        $request = new CallToolRequest('array_tool', []);
        $tool = $this->createValidTool('array_tool');
        $toolReference = new ToolReference($tool, fn () => ['key' => 'value', 'number' => 42]);
        $arrayResult = ['key' => 'value', 'number' => 42];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('array_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn($arrayResult);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertJsonStringEqualsJsonString(
            json_encode($arrayResult, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            $result->content[0]->text
        );
    }

    public function testCallHandlesContentObjectResults(): void
    {
        $request = new CallToolRequest('content_tool', []);
        $tool = $this->createValidTool('content_tool');
        $toolReference = new ToolReference($tool, fn () => new TextContent('Direct content'));
        $contentResult = new TextContent('Direct content');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('content_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn($contentResult);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertSame($contentResult, $result->content[0]);
    }

    public function testCallHandlesArrayOfContentResults(): void
    {
        $request = new CallToolRequest('content_array_tool', []);
        $tool = $this->createValidTool('content_array_tool');
        $toolReference = new ToolReference($tool, fn () => [
            new TextContent('First content'),
            new TextContent('Second content'),
        ]);
        $contentArray = [
            new TextContent('First content'),
            new TextContent('Second content'),
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('content_array_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn($contentArray);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(2, $result->content);
        $this->assertSame($contentArray[0], $result->content[0]);
        $this->assertSame($contentArray[1], $result->content[1]);
    }

    public function testCallWithDifferentExceptionTypes(): void
    {
        $request = new CallToolRequest('error_tool', []);
        $tool = $this->createValidTool('error_tool');
        $toolReference = new ToolReference($tool, fn () => throw new \InvalidArgumentException('Invalid input'));
        $handlerException = new \InvalidArgumentException('Invalid input');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('error_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willThrowException($handlerException);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Tool execution failed',
                $this->callback(function ($context) {
                    return 'error_tool' === $context['name']
                        && 'Invalid input' === $context['exception']
                        && isset($context['trace']);
                })
            );

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Execution of tool "error_tool" failed with error: "Invalid input".');

        $this->toolExecutor->call($request);
    }

    public function testCallLogsResultTypeCorrectlyForString(): void
    {
        $request = new CallToolRequest('string_tool', []);
        $tool = $this->createValidTool('string_tool');
        $toolReference = new ToolReference($tool, fn () => 'string result');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('string_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn('string result');

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
    }

    public function testCallLogsResultTypeCorrectlyForInteger(): void
    {
        $request = new CallToolRequest('int_tool', []);
        $tool = $this->createValidTool('int_tool');
        $toolReference = new ToolReference($tool, fn () => 42);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('int_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn(42);

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
    }

    public function testCallLogsResultTypeCorrectlyForArray(): void
    {
        $request = new CallToolRequest('array_tool', []);
        $tool = $this->createValidTool('array_tool');
        $toolReference = new ToolReference($tool, fn () => ['test']);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('array_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn(['test']);

        $this->logger
            ->expects($this->exactly(2))
            ->method('debug');

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
    }

    public function testConstructorWithDefaultLogger(): void
    {
        $executor = new DefaultToolExecutor($this->referenceProvider, $this->referenceHandler);

        // Verify it's constructed without throwing exceptions
        $this->assertInstanceOf(DefaultToolExecutor::class, $executor);
    }

    public function testCallHandlesEmptyArrayResult(): void
    {
        $request = new CallToolRequest('empty_array_tool', []);
        $tool = $this->createValidTool('empty_array_tool');
        $toolReference = new ToolReference($tool, fn () => []);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('empty_array_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn([]);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('[]', $result->content[0]->text);
    }

    public function testCallHandlesMixedContentAndNonContentArray(): void
    {
        $request = new CallToolRequest('mixed_tool', []);
        $tool = $this->createValidTool('mixed_tool');
        $mixedResult = [
            new TextContent('First content'),
            'plain string',
            42,
            new TextContent('Second content'),
        ];
        $toolReference = new ToolReference($tool, fn () => $mixedResult);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('mixed_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn($mixedResult);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        // The ToolReference.formatResult should handle this mixed array
        $this->assertGreaterThan(1, \count($result->content));
    }

    public function testCallHandlesStdClassResult(): void
    {
        $request = new CallToolRequest('object_tool', []);
        $tool = $this->createValidTool('object_tool');
        $objectResult = new \stdClass();
        $objectResult->property = 'value';
        $toolReference = new ToolReference($tool, fn () => $objectResult);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('object_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn($objectResult);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertStringContainsString('"property": "value"', $result->content[0]->text);
    }

    public function testCallHandlesBooleanFalseResult(): void
    {
        $request = new CallToolRequest('false_tool', []);
        $tool = $this->createValidTool('false_tool');
        $toolReference = new ToolReference($tool, fn () => false);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getTool')
            ->with('false_tool')
            ->willReturn($toolReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($toolReference, [])
            ->willReturn(false);

        $result = $this->toolExecutor->call($request);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertEquals('false', $result->content[0]->text);
    }

    private function createValidTool(string $name): Tool
    {
        return new Tool(
            name: $name,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'param' => ['type' => 'string'],
                ],
                'required' => null,
            ],
            description: "Test tool: {$name}",
            annotations: null,
        );
    }
}
