<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Prompt;

use Mcp\Capability\Prompt\Completion\EnumCompletionProvider;
use Mcp\Capability\Prompt\DefaultPromptGetter;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\RegistryException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DefaultPromptGetterTest extends TestCase
{
    private DefaultPromptGetter $promptGetter;
    private ReferenceProviderInterface|MockObject $referenceProvider;
    private ReferenceHandlerInterface|MockObject $referenceHandler;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);

        $this->promptGetter = new DefaultPromptGetter(
            $this->referenceProvider,
            $this->referenceHandler,
        );
    }

    public function testGetExecutesPromptSuccessfully(): void
    {
        $request = new GetPromptRequest('test_prompt', ['param' => 'value']);
        $prompt = $this->createValidPrompt('test_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'test result');
        $handlerResult = [
            'role' => 'user',
            'content' => 'Generated prompt content',
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('test_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['param' => 'value'])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
        $this->assertInstanceOf(PromptMessage::class, $result->messages[0]);
        $this->assertEquals(Role::User, $result->messages[0]->role);
        $this->assertInstanceOf(TextContent::class, $result->messages[0]->content);
        $this->assertEquals('Generated prompt content', $result->messages[0]->content->text);
    }

    public function testGetWithEmptyArguments(): void
    {
        $request = new GetPromptRequest('empty_args_prompt', []);
        $prompt = $this->createValidPrompt('empty_args_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Empty args content');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('empty_args_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn([
                'role' => 'user',
                'content' => 'Empty args content',
            ]);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
    }

    public function testGetWithComplexArguments(): void
    {
        $arguments = [
            'string_param' => 'value',
            'int_param' => 42,
            'bool_param' => true,
            'array_param' => ['nested' => 'data'],
            'null_param' => null,
        ];
        $request = new GetPromptRequest('complex_prompt', $arguments);
        $prompt = $this->createValidPrompt('complex_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Complex content');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('complex_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, $arguments)
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Complex response',
            ]);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
    }

    public function testGetThrowsInvalidArgumentExceptionWhenPromptNotFound(): void
    {
        $request = new GetPromptRequest('nonexistent_prompt', ['param' => 'value']);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('nonexistent_prompt')
            ->willReturn(null);

        $this->referenceHandler
            ->expects($this->never())
            ->method('handle');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt "nonexistent_prompt" is not registered.');

        $this->promptGetter->get($request);
    }

    public function testGetThrowsRegistryExceptionWhenHandlerFails(): void
    {
        $request = new GetPromptRequest('failing_prompt', ['param' => 'value']);
        $prompt = $this->createValidPrompt('failing_prompt');
        $promptReference = new PromptReference($prompt, fn () => throw new \RuntimeException('Handler failed'));
        $handlerException = RegistryException::internalError('Handler failed');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('failing_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['param' => 'value'])
            ->willThrowException($handlerException);

        $this->expectException(RegistryException::class);

        $this->promptGetter->get($request);
    }

    public function testGetHandlesJsonExceptionDuringFormatting(): void
    {
        $request = new GetPromptRequest('json_error_prompt', []);
        $prompt = $this->createValidPrompt('json_error_prompt');

        // Create a mock PromptReference that will throw JsonException during formatResult
        $promptReference = $this->createMock(PromptReference::class);
        $promptReference->expects($this->once())
            ->method('formatResult')
            ->willThrowException(new \JsonException('JSON encoding failed'));

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('json_error_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn('some result');

        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('JSON encoding failed');

        $this->promptGetter->get($request);
    }

    public function testGetHandlesArrayOfMessages(): void
    {
        $request = new GetPromptRequest('multi_message_prompt', ['context' => 'test']);
        $prompt = $this->createValidPrompt('multi_message_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Multiple messages');
        $handlerResult = [
            [
                'role' => 'user',
                'content' => 'First message',
            ],
            [
                'role' => 'assistant',
                'content' => 'Second message',
            ],
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('multi_message_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['context' => 'test'])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(2, $result->messages);
        $this->assertEquals(Role::User, $result->messages[0]->role);
        $this->assertEquals('First message', $result->messages[0]->content->text);
        $this->assertEquals(Role::Assistant, $result->messages[1]->role);
        $this->assertEquals('Second message', $result->messages[1]->content->text);
    }

    public function testGetHandlesPromptMessageObjects(): void
    {
        $request = new GetPromptRequest('prompt_message_prompt', []);
        $prompt = $this->createValidPrompt('prompt_message_prompt');
        $promptMessage = new PromptMessage(
            Role::User,
            new TextContent('Direct prompt message')
        );
        $promptReference = new PromptReference($prompt, fn () => $promptMessage);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('prompt_message_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($promptMessage);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
        $this->assertSame($promptMessage, $result->messages[0]);
    }

    public function testGetHandlesUserAssistantStructure(): void
    {
        $request = new GetPromptRequest('user_assistant_prompt', []);
        $prompt = $this->createValidPrompt('user_assistant_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Conversation content');
        $handlerResult = [
            'user' => 'What is the weather?',
            'assistant' => 'I can help you check the weather.',
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('user_assistant_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(2, $result->messages);
        $this->assertEquals(Role::User, $result->messages[0]->role);
        $this->assertEquals('What is the weather?', $result->messages[0]->content->text);
        $this->assertEquals(Role::Assistant, $result->messages[1]->role);
        $this->assertEquals('I can help you check the weather.', $result->messages[1]->content->text);
    }

    public function testGetHandlesEmptyArrayResult(): void
    {
        $request = new GetPromptRequest('empty_array_prompt', []);
        $prompt = $this->createValidPrompt('empty_array_prompt');
        $promptReference = new PromptReference($prompt, fn () => []);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('empty_array_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn([]);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(0, $result->messages);
    }

    public function testGetHandlesDifferentExceptionTypes(): void
    {
        $request = new GetPromptRequest('error_prompt', []);
        $prompt = $this->createValidPrompt('error_prompt');
        $promptReference = new PromptReference($prompt, fn () => throw new \InvalidArgumentException('Invalid input'));
        $handlerException = new \InvalidArgumentException('Invalid input');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('error_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willThrowException($handlerException);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid input');

        $this->promptGetter->get($request);
    }

    public function testGetWithTypedContentStructure(): void
    {
        $request = new GetPromptRequest('typed_content_prompt', []);
        $prompt = $this->createValidPrompt('typed_content_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Typed content');
        $handlerResult = [
            'role' => 'user',
            'content' => [
                'type' => 'text',
                'text' => 'Typed text content',
            ],
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('typed_content_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
        $this->assertEquals(Role::User, $result->messages[0]->role);
        $this->assertEquals('Typed text content', $result->messages[0]->content->text);
    }

    public function testGetWithPromptReferenceHavingCompletionProviders(): void
    {
        $request = new GetPromptRequest('completion_prompt', ['param' => 'value']);
        $prompt = $this->createValidPrompt('completion_prompt');
        $completionProviders = ['param' => EnumCompletionProvider::class];
        $promptReference = new PromptReference(
            $prompt,
            fn () => 'Completion content',
            false,
            $completionProviders
        );

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('completion_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, ['param' => 'value'])
            ->willReturn([
                'role' => 'user',
                'content' => 'Completion content',
            ]);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
    }

    public function testGetHandlesMixedMessageArray(): void
    {
        $request = new GetPromptRequest('mixed_prompt', []);
        $prompt = $this->createValidPrompt('mixed_prompt');
        $promptMessage = new PromptMessage(Role::Assistant, new TextContent('Direct message'));
        $promptReference = new PromptReference($prompt, fn () => 'Mixed content');
        $handlerResult = [
            $promptMessage,
            [
                'role' => 'user',
                'content' => 'Regular message',
            ],
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('mixed_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(2, $result->messages);
        $this->assertSame($promptMessage, $result->messages[0]);
        $this->assertEquals(Role::User, $result->messages[1]->role);
        $this->assertEquals('Regular message', $result->messages[1]->content->text);
    }

    public function testGetReflectsFormattedMessagesCorrectly(): void
    {
        $request = new GetPromptRequest('format_test_prompt', []);
        $prompt = $this->createValidPrompt('format_test_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Format test');

        // Test that the formatted result from PromptReference.formatResult is properly returned
        $handlerResult = [
            'role' => 'user',
            'content' => 'Test formatting',
        ];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('format_test_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($handlerResult);

        $result = $this->promptGetter->get($request);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
        $this->assertEquals('Test formatting', $result->messages[0]->content->text);
        $this->assertEquals(Role::User, $result->messages[0]->role);
    }

    /**
     * Test that invalid handler results throw RuntimeException from PromptReference.formatResult().
     */
    public function testGetThrowsRuntimeExceptionForInvalidHandlerResult(): void
    {
        $request = new GetPromptRequest('invalid_prompt', []);
        $prompt = $this->createValidPrompt('invalid_prompt');
        $promptReference = new PromptReference($prompt, fn () => 'Invalid content');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('invalid_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn('This is not a valid prompt format');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt generator method must return an array of messages.');

        $this->promptGetter->get($request);
    }

    /**
     * Test that null result from handler throws RuntimeException.
     */
    public function testGetThrowsRuntimeExceptionForNullHandlerResult(): void
    {
        $request = new GetPromptRequest('null_prompt', []);
        $prompt = $this->createValidPrompt('null_prompt');
        $promptReference = new PromptReference($prompt, fn () => null);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('null_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt generator method must return an array of messages.');

        $this->promptGetter->get($request);
    }

    /**
     * Test that scalar result from handler throws RuntimeException.
     */
    public function testGetThrowsRuntimeExceptionForScalarHandlerResult(): void
    {
        $request = new GetPromptRequest('scalar_prompt', []);
        $prompt = $this->createValidPrompt('scalar_prompt');
        $promptReference = new PromptReference($prompt, fn () => 42);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('scalar_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn(42);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt generator method must return an array of messages.');

        $this->promptGetter->get($request);
    }

    /**
     * Test that boolean result from handler throws RuntimeException.
     */
    public function testGetThrowsRuntimeExceptionForBooleanHandlerResult(): void
    {
        $request = new GetPromptRequest('boolean_prompt', []);
        $prompt = $this->createValidPrompt('boolean_prompt');
        $promptReference = new PromptReference($prompt, fn () => true);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('boolean_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt generator method must return an array of messages.');

        $this->promptGetter->get($request);
    }

    /**
     * Test that object result from handler throws RuntimeException.
     */
    public function testGetThrowsRuntimeExceptionForObjectHandlerResult(): void
    {
        $request = new GetPromptRequest('object_prompt', []);
        $prompt = $this->createValidPrompt('object_prompt');
        $objectResult = new \stdClass();
        $objectResult->property = 'value';
        $promptReference = new PromptReference($prompt, fn () => $objectResult);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getPrompt')
            ->with('object_prompt')
            ->willReturn($promptReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($promptReference, [])
            ->willReturn($objectResult);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt generator method must return an array of messages.');

        $this->promptGetter->get($request);
    }

    private function createValidPrompt(string $name): Prompt
    {
        return new Prompt(
            name: $name,
            description: "Test prompt: {$name}",
            arguments: null,
        );
    }
}
