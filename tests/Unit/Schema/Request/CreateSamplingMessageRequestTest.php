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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Enum\ToolChoiceMode;
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
}
