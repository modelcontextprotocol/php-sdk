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

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\Handler\Request\GetPromptHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GetPromptHandlerTest extends TestCase
{
    private GetPromptHandler $handler;
    private ReferenceProviderInterface&MockObject $referenceProvider;
    private ReferenceHandlerInterface&MockObject $referenceHandler;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new GetPromptHandler($this->referenceProvider, $this->referenceHandler);
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
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('greeting_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['_session' => $this->session])
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedResult, $response->result);
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
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('personalized_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, array_merge($arguments, ['_session' => $this->session]))
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithNullArguments(): void
    {
        $request = $this->createGetPromptRequest('simple_prompt', null);
        $expectedMessages = [
            new PromptMessage(Role::Assistant, new TextContent('I am ready to help.')),
        ];
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('simple_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['_session' => $this->session])
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithEmptyArguments(): void
    {
        $request = $this->createGetPromptRequest('empty_args_prompt', []);
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Default message')),
        ];
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('empty_args_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['_session' => $this->session])
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandlePromptGetWithMultipleMessages(): void
    {
        $request = $this->createGetPromptRequest('conversation_prompt');
        $expectedMessages = [
            new PromptMessage(Role::User, new TextContent('Hello')),
            new PromptMessage(Role::Assistant, new TextContent('Hi there! How can I help you today?')),
            new PromptMessage(Role::User, new TextContent('I need assistance with my project')),
        ];
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('conversation_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['_session' => $this->session])
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandlePromptNotFoundExceptionReturnsError(): void
    {
        $request = $this->createGetPromptRequest('nonexistent_prompt');
        $exception = new PromptNotFoundException($request);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('nonexistent_prompt')
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals('Prompt not found for name: "nonexistent_prompt".', $response->message);
    }

    public function testHandlePromptGetExceptionReturnsError(): void
    {
        $request = $this->createGetPromptRequest('failing_prompt');
        $exception = new PromptGetException('Failed to get prompt');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('failing_prompt')
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Failed to get prompt', $response->message);
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
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('complex_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, array_merge($arguments, ['_session' => $this->session]))
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
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
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('unicode_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, array_merge($arguments, ['_session' => $this->session]))
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandlePromptGetReturnsEmptyMessages(): void
    {
        $request = $this->createGetPromptRequest('empty_prompt');
        $expectedResult = new GetPromptResult([]);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('empty_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['_session' => $this->session])
            ->willReturn([]);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with([])
            ->willReturn([]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
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
        $expectedResult = new GetPromptResult($expectedMessages);

        $promptReference = $this->createMock(PromptReference::class);
        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('many_args_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, array_merge($arguments, ['_session' => $this->session]))
            ->willReturn($expectedMessages);

        $promptReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($expectedMessages)
            ->willReturn($expectedMessages);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    /**
     * @param array<string, mixed>|null $arguments
     */
    private function createGetPromptRequest(string $name, ?array $arguments = null): GetPromptRequest
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
