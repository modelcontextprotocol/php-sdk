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

use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Registry;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\PromptReference as PromptRefSchema;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceReference as ResourceRefSchema;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Handler\Request\CompletionCompleteHandler;
use Mcp\Server\Session\SessionInterface;
use Mcp\Tests\Unit\Capability\Provider\Fixtures\TestDynamicPromptProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CompletionCompleteHandlerTest extends TestCase
{
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
    }

    public function testCompletionFromDynamicPromptProvider(): void
    {
        $prompt = new Prompt(
            name: 'dynamic_prompt',
            description: 'A dynamic prompt',
            arguments: [new PromptArgument('format', 'Output format', true)],
        );

        $completionProvider = new ListCompletionProvider(['json', 'yaml', 'xml']);

        $provider = new TestDynamicPromptProvider(
            [$prompt],
            ['dynamic_prompt' => ['format' => $completionProvider]]
        );

        $registry = new Registry(null, new NullLogger());
        $registry->registerDynamicPromptProvider($provider);

        $handler = new CompletionCompleteHandler($registry);

        $request = $this->createCompletionRequest(
            new PromptRefSchema('dynamic_prompt'),
            'format',
            'j'
        );

        $response = $handler->handle($request, $this->session);

        if ($response instanceof Error) {
            $this->fail('Expected Response, got Error: '.$response->message);
        }
        $this->assertInstanceOf(Response::class, $response);
        $result = $response->result;
        $this->assertInstanceOf(CompletionCompleteResult::class, $result);
        $this->assertContains('json', $result->values);
    }

    public function testCompletionFallsBackToStaticWhenNoDynamicProvider(): void
    {
        $prompt = new Prompt(
            name: 'static_prompt',
            description: 'A static prompt',
            arguments: [new PromptArgument('type', 'Type', true)],
        );

        $completionProvider = new ListCompletionProvider(['alpha', 'beta', 'gamma']);

        $registry = new Registry(null, new NullLogger());
        $registry->registerPrompt($prompt, fn () => [], ['type' => $completionProvider]);

        $handler = new CompletionCompleteHandler($registry);

        $request = $this->createCompletionRequest(
            new PromptRefSchema('static_prompt'),
            'type',
            ''
        );

        $response = $handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $result = $response->result;
        $this->assertContains('alpha', $result->values);
        $this->assertContains('beta', $result->values);
        $this->assertContains('gamma', $result->values);
    }

    public function testCompletionReturnsEmptyWhenNoProviderForArgument(): void
    {
        $prompt = new Prompt(
            name: 'partial_prompt',
            description: 'A prompt with some completion providers',
            arguments: [
                new PromptArgument('with_completion', 'Has completion', true),
                new PromptArgument('no_completion', 'No completion', true),
            ],
        );

        $completionProvider = new ListCompletionProvider(['a', 'b', 'c']);

        $provider = new TestDynamicPromptProvider(
            [$prompt],
            ['partial_prompt' => ['with_completion' => $completionProvider]]
        );

        $registry = new Registry(null, new NullLogger());
        $registry->registerDynamicPromptProvider($provider);

        $handler = new CompletionCompleteHandler($registry);

        // Request completion for argument without provider
        $request = $this->createCompletionRequest(
            new PromptRefSchema('partial_prompt'),
            'no_completion',
            'test'
        );

        $response = $handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $result = $response->result;
        $this->assertEmpty($result->values);
    }

    public function testCompletionForResourceTemplateStillWorks(): void
    {
        $template = new ResourceTemplate(
            uriTemplate: 'test://entity/{id}',
            name: 'test_template',
            description: 'A test template',
            mimeType: 'application/json',
        );

        $completionProvider = new ListCompletionProvider(['1', '2', '3', '10', '100']);

        $registry = new Registry(null, new NullLogger());
        $registry->registerResourceTemplate(
            $template,
            fn () => 'content',
            ['id' => $completionProvider]
        );

        $handler = new CompletionCompleteHandler($registry);

        $request = $this->createCompletionRequest(
            new ResourceRefSchema('test://entity/{id}'),
            'id',
            '1'
        );

        $response = $handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $result = $response->result;
        $this->assertContains('1', $result->values);
        $this->assertContains('10', $result->values);
        $this->assertContains('100', $result->values);
    }

    public function testCompletionPromptNotFoundReturnsError(): void
    {
        $registry = new Registry(null, new NullLogger());
        $handler = new CompletionCompleteHandler($registry);

        $request = $this->createCompletionRequest(
            new PromptRefSchema('nonexistent_prompt'),
            'arg',
            'test'
        );

        $response = $handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
    }

    private function createCompletionRequest(
        PromptRefSchema|ResourceRefSchema $ref,
        string $argumentName,
        string $argumentValue,
    ): CompletionCompleteRequest {
        $refArray = $ref instanceof PromptRefSchema
            ? ['type' => 'ref/prompt', 'name' => $ref->name]
            : ['type' => 'ref/resource', 'uri' => $ref->uri];

        return CompletionCompleteRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => CompletionCompleteRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'ref' => $refArray,
                'argument' => [
                    'name' => $argumentName,
                    'value' => $argumentValue,
                ],
            ],
        ]);
    }
}
