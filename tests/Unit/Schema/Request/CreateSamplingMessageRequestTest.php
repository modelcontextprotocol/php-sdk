<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Request;

use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Enum\ToolChoiceMode;
use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolChoice;
use PHPUnit\Framework\TestCase;

final class CreateSamplingMessageRequestTest extends TestCase
{
    public function testConstructorWithValidSetOfMessages(): void
    {
        $messages = [
            new SamplingMessage(Role::User, new TextContent('My name is George.')),
            new SamplingMessage(Role::Assistant, new TextContent('Hi George, nice to meet you!')),
            new SamplingMessage(Role::User, new TextContent('What is my name?')),
        ];

        $request = new CreateSamplingMessageRequest($messages, 150);

        $this->assertCount(3, $request->messages);
        $this->assertSame(150, $request->maxTokens);
    }

    public function testConstructorWithInvalidSetOfMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Messages must be instance of SamplingMessage.');

        $messages = [
            new SamplingMessage(Role::User, new TextContent('My name is George.')),
            new SamplingMessage(Role::Assistant, new TextContent('Hi George, nice to meet you!')),
            new TextContent('What is my name?'),
        ];

        /* @phpstan-ignore argument.type */
        new CreateSamplingMessageRequest($messages, 150);
    }

    public function testToolsAndToolChoiceRoundTrip(): void
    {
        $tool = new Tool('weather', null, ['type' => 'object', 'properties' => [], 'required' => null], 'Get weather', null);
        $request = new CreateSamplingMessageRequest(
            [new SamplingMessage(Role::User, new TextContent('Weather in Paris?'))],
            150,
            tools: [$tool],
            toolChoice: new ToolChoice(ToolChoiceMode::Required),
        );

        $payload = $request->withId(1)->jsonSerialize();
        $this->assertSame('weather', $payload['params']['tools'][0]->name);
        $this->assertSame(ToolChoiceMode::Required, $payload['params']['toolChoice']->mode);

        $hydrated = CreateSamplingMessageRequest::fromArray(json_decode(json_encode($payload, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame('weather', $hydrated->tools[0]->name);
        $this->assertSame(ToolChoiceMode::Required, $hydrated->toolChoice->mode);
    }

    public function testValidToolFlowPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->requestFor([
            new SamplingMessage(Role::User, new TextContent('Weather in Paris and London?')),
            new SamplingMessage(Role::Assistant, [
                new ToolUseContent('call-1', 'weather', ['city' => 'Paris']),
                new ToolUseContent('call-2', 'weather', ['city' => 'London']),
            ]),
            new SamplingMessage(Role::User, [
                new ToolResultContent('call-1', [new TextContent('18 C')]),
                new ToolResultContent('call-2', [new TextContent('15 C')]),
            ]),
            new SamplingMessage(Role::Assistant, new TextContent('Paris is warmer.')),
        ])->validateToolFlow();
    }

    public function testToolResultsMixedWithOtherContentAreRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', [])),
            new SamplingMessage(Role::User, [
                new ToolResultContent('call-1', [new TextContent('18 C')]),
                new TextContent('and also...'),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool results mixed with other content.');

        $request->validateToolFlow();
    }

    public function testToolUseInUserMessageIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::User, new ToolUseContent('call-1', 'weather', [])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ToolUseContent is only valid in assistant sampling messages.');

        $request->validateToolFlow();
    }

    public function testToolResultInAssistantMessageIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::Assistant, new ToolResultContent('call-1', [new TextContent('18 C')])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ToolResultContent is only valid in user sampling messages.');

        $request->validateToolFlow();
    }

    public function testUnansweredToolUseIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::Assistant, [
                new ToolUseContent('call-1', 'weather', []),
                new ToolUseContent('call-2', 'weather', []),
            ]),
            new SamplingMessage(Role::User, [new ToolResultContent('call-1', [new TextContent('18 C')])]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool result missing in request.');

        $request->validateToolFlow();
    }

    public function testTrailingToolUseIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', [])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool result missing in request.');

        $request->validateToolFlow();
    }

    public function testToolUseFollowedByPlainMessageIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', [])),
            new SamplingMessage(Role::User, new TextContent('never mind')),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool result missing in request.');

        $request->validateToolFlow();
    }

    public function testUnsolicitedToolResultIsRejected(): void
    {
        $request = $this->requestFor([
            new SamplingMessage(Role::User, new ToolResultContent('call-9', [new TextContent('18 C')])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool result "call-9" does not answer a preceding tool use.');

        $request->validateToolFlow();
    }

    /**
     * @param SamplingMessage[] $messages
     */
    private function requestFor(array $messages): CreateSamplingMessageRequest
    {
        return new CreateSamplingMessageRequest($messages, 150);
    }
}
