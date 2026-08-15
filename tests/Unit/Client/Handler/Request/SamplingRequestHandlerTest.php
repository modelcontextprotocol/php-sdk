<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Handler\Request;

use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SamplingRequestHandlerTest extends TestCase
{
    public function testHandleReturnsResponseForValidToolFlow(): void
    {
        $handler = new SamplingRequestHandler($this->callbackReturningText());

        $request = $this->requestFor([
            new SamplingMessage(Role::User, new TextContent('Weather in Paris?')),
            new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', ['city' => 'Paris'])),
            new SamplingMessage(Role::User, new ToolResultContent('call-1', [new TextContent('18 C')])),
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($request->getId(), $response->id);
    }

    /**
     * The spec asks for -32602 on both of these, and the callback must not run.
     *
     * @return iterable<string, array{SamplingMessage[], string}>
     */
    public static function provideToolFlowViolations(): iterable
    {
        yield 'tool results mixed with other content' => [
            [
                new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', [])),
                new SamplingMessage(Role::User, [
                    new ToolResultContent('call-1', [new TextContent('18 C')]),
                    new TextContent('and also...'),
                ]),
            ],
            'Tool results mixed with other content.',
        ];

        yield 'tool result missing' => [
            [new SamplingMessage(Role::Assistant, new ToolUseContent('call-1', 'weather', []))],
            'Tool result missing in request.',
        ];
    }

    /**
     * @param SamplingMessage[] $messages
     */
    #[DataProvider('provideToolFlowViolations')]
    public function testHandleRejectsToolFlowViolationsWithInvalidParams(array $messages, string $expectedMessage): void
    {
        $callback = new class implements SamplingCallbackInterface {
            public bool $invoked = false;

            public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
            {
                $this->invoked = true;

                throw new \LogicException('The callback must not run for an invalid request.');
            }
        };

        $request = $this->requestFor($messages);
        $response = (new SamplingRequestHandler($callback))->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::INVALID_PARAMS, $response->code);
        $this->assertSame($expectedMessage, $response->message);
        $this->assertSame($request->getId(), $response->id);
        $this->assertFalse($callback->invoked);
    }

    /**
     * @param SamplingMessage[] $messages
     */
    private function requestFor(array $messages): CreateSamplingMessageRequest
    {
        return (new CreateSamplingMessageRequest($messages, 150))->withId('sampling-1');
    }

    private function callbackReturningText(): SamplingCallbackInterface
    {
        return new class implements SamplingCallbackInterface {
            public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
            {
                return new CreateSamplingMessageResult(Role::Assistant, new TextContent('Paris is warm.'), 'test-model');
            }
        };
    }
}
