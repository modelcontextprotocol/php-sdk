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
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Sampling: the server borrowing the client's model mid-tool-call.
 *
 * @see Fixture/sampling.php for the server under test
 */
final class SamplingTest extends IntegrationTestCase
{
    #[TestDox('the sampled completion reaches the tool that asked for it')]
    public function testSampledCompletionReachesTheTool(): void
    {
        $client = $this->connect('sampling', $this->clientSampling());

        $result = $client->callTool('summarize', ['text' => 'a long report']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('test-model said: a long report', $result->content[0]->text);
    }

    #[TestDox('the prompt the tool passed arrives at the client')]
    public function testPromptReachesTheClient(): void
    {
        // The client stays in this process, so what it was asked can be
        // collected by reference even though the tool asking runs in another.
        /** @var \ArrayObject<int, CreateSamplingMessageRequest> $seen */
        $seen = new \ArrayObject();
        $client = $this->connect('sampling', $this->clientSampling($seen));

        $client->callTool('summarize', ['text' => 'inspect me']);

        $this->assertCount(1, $seen);
        $this->assertInstanceOf(TextContent::class, $seen[0]->messages[0]->content);
        $this->assertSame('inspect me', $seen[0]->messages[0]->content->text);
        $this->assertSame(64, $seen[0]->maxTokens);
    }

    #[TestDox('a client that cannot sample refuses instead of stalling the tool')]
    public function testClientWithoutSamplingRefuses(): void
    {
        // The gateway has no supportsSampling() to consult, so the tool finds
        // out by asking and the refusal surfaces as a ClientException.
        $client = $this->connect('sampling');

        $result = $client->callTool('summarize', ['text' => 'anything']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Client does not handle "sampling/createMessage" requests.', $result->content[0]->text);
    }

    /**
     * @param \ArrayObject<int, CreateSamplingMessageRequest>|null $seen collects what the server asked for
     */
    private function clientSampling(?\ArrayObject $seen = null): ClientBuilder
    {
        $callback = new class($seen ?? new \ArrayObject()) implements SamplingCallbackInterface {
            /** @param \ArrayObject<int, CreateSamplingMessageRequest> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
            {
                $this->seen[] = $request;

                $prompt = $request->messages[0]->content;
                \assert($prompt instanceof TextContent);

                return new CreateSamplingMessageResult(Role::Assistant, new TextContent($prompt->text), 'test-model');
            }
        };

        return $this->clientBuilder()
            ->setCapabilities(new ClientCapabilities(sampling: true))
            ->addRequestHandler(new SamplingRequestHandler($callback));
    }
}
