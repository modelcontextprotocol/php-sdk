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

use Mcp\Capability\Prompt\PromptGetterInterface;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\RequestHandler\GetPromptHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GetPromptHandlerTest extends TestCase
{
    private GetPromptHandler $handler;
    private PromptGetterInterface|MockObject $promptGetter;

    protected function setUp(): void
    {
        $this->promptGetter = $this->createMock(PromptGetterInterface::class);

        $this->handler = new GetPromptHandler($this->promptGetter);
    }

    public function testSupportsGetPromptRequest(): void
    {
        $request = $this->createGetPromptRequest('test_prompt');

        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandleSuccessfulPromptGet(): void
    {
        $request = $this->createGetPromptRequest('greeting_prompt');
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Hello, how can I help you?')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A greeting prompt',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithArguments(): void
    {
        $arguments = [
            'name' => 'John',
            'context' => 'business meeting',
            'formality' => 'formal',
        ];
        $request = $this->createGetPromptRequest('personalized_prompt', $arguments);
        $expectedMessages = [
            new PromptMessage(
                Role::User,
                new TextContent('Good morning, John. How may I assist you in your business meeting?'),
            ),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A personalized greeting prompt',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithNullArguments(): void
    {
        $request = $this->createGetPromptRequest('simple_prompt', null);
        $expectedMessages = [
            new PromptMessage(Role::Assistant, new TextContent('I am ready to help.')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A simple prompt',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithEmptyArguments(): void
    {
        $request = $this->createGetPromptRequest('empty_args_prompt', []);
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Default message')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A prompt with empty arguments',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithMultipleMessages(): void
    {
        $request = $this->createGetPromptRequest('conversation_prompt');
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Hello')),
            new PromptMessage(Role::Assistant, new TextContent('Hi there! How can I help you today?')),
            new PromptMessage(Role::User, new TextContent('I need assistance with my project')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A conversation prompt',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertCount(3, $response->result->messages);
    }

    public function testHandlePromptNotFoundExceptionReturnsError(): void
    {
        $request = $this->createGetPromptRequest('nonexistent_prompt');
        $exception = new PromptNotFoundException($request);

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willThrowException($exception);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while handling prompt', $response->message);
    }

    public function testHandlePromptGetExceptionReturnsError(): void
    {
        $request = $this->createGetPromptRequest('failing_prompt');
        $exception = new PromptGetException($request, new \RuntimeException('Failed to get prompt'));

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willThrowException($exception);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while handling prompt', $response->message);
    }

    public function testHandlePromptGetWithComplexArguments(): void
    {
        $arguments = [
            'user_data' => [
                'name' => 'Alice',
                'preferences' => ['formal', 'concise'],
                'history' => [
                    'last_interaction' => '2025-01-15',
                    'topics' => ['technology', 'business'],
                ],
            ],
            'context' => 'technical consultation',
            'metadata' => [
                'session_id' => 'sess_123456',
                'timestamp' => 1705392000,
            ],
        ];
        $request = $this->createGetPromptRequest('complex_prompt', $arguments);
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Complex prompt generated with all parameters')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A complex prompt with nested arguments',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithSpecialCharacters(): void
    {
        $arguments = [
            'message' => 'Hello 世界! How are you? 😊',
            'special' => 'äöü ñ ß',
            'quotes' => 'Text with "double" and \'single\' quotes',
        ];
        $request = $this->createGetPromptRequest('unicode_prompt', $arguments);
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Unicode message processed')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A prompt handling unicode characters',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    public function testHandlePromptGetReturnsEmptyMessages(): void
    {
        $request = $this->createGetPromptRequest('empty_prompt');
        $expectedResult = new GetPromptResult(
            description: 'An empty prompt',
            messages: [],
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertCount(0, $response->result->messages);
    }

    public function testHandlePromptGetWithLargeNumberOfArguments(): void
    {
        $arguments = [];
        for ($i = 0; $i < 100; ++$i) {
            $arguments["arg_{$i}"] = "value_{$i}";
        }

        $request = $this->createGetPromptRequest('many_args_prompt', $arguments);
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Processed 100 arguments')),
        ];
        $expectedResult = new GetPromptResult(
            description: 'A prompt with many arguments',
            messages: $expectedMessages,
        );

        $this->promptGetter
            ->expects($this->once())
            ->method('get')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
    }

    private function createGetPromptRequest(string $name, ?array $arguments = null): Request
    {
        return GetPromptRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => GetPromptRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }
}
