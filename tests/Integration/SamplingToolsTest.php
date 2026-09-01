<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Sampling with tools: the multi-turn tool loop between server, client and model.
 *
 * @see Fixture/sampling_tools.php for the server under test
 */
final class SamplingToolsTest extends IntegrationTestCase
{
    #[TestDox('the server runs a full tool loop and gets the model\'s final answer')]
    public function testToolLoopCompletes(): void
    {
        $client = $this->connect('sampling_tools', $this->clientSamplingWithTools());

        $result = $client->callTool('weather_report', ['city' => 'Paris']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Paris: 18 C is the answer. (endTurn)', $result->content[0]->text);
    }

    #[TestDox('the tools the server offered arrive at the client')]
    public function testToolsReachTheClient(): void
    {
        /** @var \ArrayObject<int, CreateSamplingMessageRequest> $seen */
        $seen = new \ArrayObject();
        $client = $this->connect('sampling_tools', $this->clientSamplingWithTools($seen));

        $client->callTool('weather_report', ['city' => 'Paris']);

        $this->assertCount(2, $seen);

        $first = $seen[0];
        $this->assertInstanceOf(CreateSamplingMessageRequest::class, $first);
        $this->assertNotNull($first->tools);
        $this->assertSame('get_weather', $first->tools[0]->name);

        // Second turn carries the assistant's tool use and the server's tool result.
        $second = $seen[1];
        $this->assertInstanceOf(CreateSamplingMessageRequest::class, $second);
        $this->assertCount(3, $second->messages);
        $this->assertInstanceOf(ToolUseContent::class, $second->messages[1]->getContentBlocks()[0]);
        $toolResult = $second->messages[2]->getContentBlocks()[0];
        $this->assertInstanceOf(ToolResultContent::class, $toolResult);
        $this->assertSame('call-1', $toolResult->toolUseId);
    }

    #[TestDox('a client that did not advertise sampling.tools is never sent tools')]
    public function testClientWithoutSamplingToolsIsNotOfferedTools(): void
    {
        $client = $this->connect('sampling_tools', $this->clientBuilder()
            ->setCapabilities(new ClientCapabilities(sampling: true))
            ->addRequestHandler(new SamplingRequestHandler($this->neverCalled())));

        $result = $client->callTool('weather_report', ['city' => 'Paris']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('client cannot use tools during sampling', $result->content[0]->text);
    }

    /**
     * @param \ArrayObject<int, CreateSamplingMessageRequest>|null $seen collects what the server asked for
     */
    private function clientSamplingWithTools(?\ArrayObject $seen = null): ClientBuilder
    {
        $callback = new class($seen ?? new \ArrayObject()) implements SamplingCallbackInterface {
            /** @param \ArrayObject<int, CreateSamplingMessageRequest> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
            {
                $this->seen[] = $request;

                $lastMessage = $request->messages[\count($request->messages) - 1];
                $answeredTools = array_filter(
                    $lastMessage->getContentBlocks(),
                    static fn ($block): bool => $block instanceof ToolResultContent,
                );

                // First turn: ask for the tool. Second: answer from its result.
                if ([] === $answeredTools) {
                    return new CreateSamplingMessageResult(
                        Role::Assistant,
                        [new ToolUseContent('call-1', 'get_weather', ['city' => 'Paris'])],
                        'test-model',
                        'toolUse',
                    );
                }

                $toolResult = reset($answeredTools);
                $text = $toolResult->content[0];
                \assert($text instanceof TextContent);

                return new CreateSamplingMessageResult(
                    Role::Assistant,
                    new TextContent(\sprintf('%s is the answer.', $text->text)),
                    'test-model',
                    'endTurn',
                );
            }
        };

        return $this->clientBuilder()
            ->setCapabilities(new ClientCapabilities(sampling: true, samplingTools: true))
            ->addRequestHandler(new SamplingRequestHandler($callback));
    }

    private function neverCalled(): SamplingCallbackInterface
    {
        return new class implements SamplingCallbackInterface {
            public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
            {
                throw new \LogicException('The server must not sample a client that cannot use tools.');
            }
        };
    }
}
