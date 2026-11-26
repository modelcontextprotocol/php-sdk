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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Capability\Completion\ContextAwareProviderInterface;
use Mcp\Server\Capability\Completion\ProviderInterface;
use Mcp\Server\Capability\Registry\ResourceReference;
use Mcp\Server\Capability\Registry\ResourceTemplateReference;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Handler\Request\CompletionCompleteHandler;
use Mcp\Server\Session\SessionInterface;
use Mcp\Tests\Unit\Server\Capability\Attribute\CompletionProviderFixture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CompletionCompleteHandlerTest extends TestCase
{
    private CompletionCompleteHandler $handler;
    private RegistryInterface&MockObject $registry;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new CompletionCompleteHandler($this->registry);
    }

    public function testReturnsEmptyCompletionForResourceWithoutTemplate(): void
    {
        $uri = 'file://static/readme.txt';
        $request = $this->createCompletionRequest($uri, ['name' => 'arg', 'value' => 'a']);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($this->createMock(ResourceReference::class));

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(new CompletionCompleteResult([]), $response->result);
    }

    public function testReturnsCompletionsForResourceTemplate(): void
    {
        $uri = 'file://users/alice';
        $request = $this->createCompletionRequest($uri, ['name' => 'id', 'value' => 'al']);

        $templateReference = new ResourceTemplateReference(
            new ResourceTemplate('file://users/{id}', 'user'),
            static fn () => null,
            ['id' => new CompletionProviderFixture()],
        );

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($templateReference);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(new CompletionCompleteResult(['alpha'], 1, false), $response->result);
    }

    public function testPassesContextArgumentsToAContextAwareProvider(): void
    {
        $uri = 'file://users/alice/repos/sdk';
        $request = $this->createCompletionRequest($uri, ['name' => 'repo', 'value' => 's'], ['arguments' => ['owner' => 'alice']]);

        $provider = $this->createMock(ContextAwareProviderInterface::class);
        $provider->expects($this->never())->method('getCompletions');
        $provider->expects($this->once())
            ->method('getCompletionsWithContext')
            ->with('s', ['owner' => 'alice'])
            ->willReturn(['sdk', 'site']);

        $this->registry
            ->method('getResource')
            ->with($uri)
            ->willReturn($this->templateReference(['repo' => $provider]));

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(new CompletionCompleteResult(['sdk', 'site'], 2, false), $response->result);
    }

    public function testPassesNoArgumentsToAContextAwareProviderWithoutContext(): void
    {
        $uri = 'file://users/alice/repos/sdk';
        $request = $this->createCompletionRequest($uri, ['name' => 'repo', 'value' => 's']);

        $provider = $this->createMock(ContextAwareProviderInterface::class);
        $provider->expects($this->once())
            ->method('getCompletionsWithContext')
            ->with('s', [])
            ->willReturn(['sdk']);

        $this->registry
            ->method('getResource')
            ->with($uri)
            ->willReturn($this->templateReference(['repo' => $provider]));

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(new CompletionCompleteResult(['sdk'], 1, false), $response->result);
    }

    public function testPlainProviderIgnoresContext(): void
    {
        $uri = 'file://users/alice/repos/sdk';
        $request = $this->createCompletionRequest($uri, ['name' => 'repo', 'value' => 's'], ['arguments' => ['owner' => 'alice']]);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects($this->once())
            ->method('getCompletions')
            ->with('s')
            ->willReturn(['sdk']);

        $this->registry
            ->method('getResource')
            ->with($uri)
            ->willReturn($this->templateReference(['repo' => $provider]));

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(new CompletionCompleteResult(['sdk'], 1, false), $response->result);
    }

    public function testLogsProviderFailureBeforeReturningInternalError(): void
    {
        $uri = 'file://users/alice';
        $request = $this->createCompletionRequest($uri, ['name' => 'id', 'value' => 'al']);

        $exception = new \RuntimeException('Provider blew up');
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getCompletions')->willThrowException($exception);

        $templateReference = new ResourceTemplateReference(
            new ResourceTemplate('file://users/{id}', 'user'),
            static fn () => null,
            ['id' => $provider],
        );

        $this->registry
            ->method('getResource')
            ->with($uri)
            ->willReturn($templateReference);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Error while handling completion request: Provider blew up',
                ['exception' => $exception],
            );

        $handler = new CompletionCompleteHandler($this->registry, null, $logger);
        $response = $handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame('Error while handling completion request', $response->message);
    }

    /**
     * @param array<string, ProviderInterface> $providers
     */
    private function templateReference(array $providers): ResourceTemplateReference
    {
        return new ResourceTemplateReference(
            new ResourceTemplate('file://users/{owner}/repos/{repo}', 'repository'),
            static fn () => null,
            $providers,
        );
    }

    /**
     * @param array{ name: string, value: string }            $argument
     * @param array{ arguments?: array<string, string> }|null $context
     */
    private function createCompletionRequest(string $uri, array $argument, ?array $context = null): CompletionCompleteRequest
    {
        $params = [
            'ref' => ['type' => 'ref/resource', 'uri' => $uri],
            'argument' => $argument,
        ];
        if (null !== $context) {
            $params['context'] = $context;
        }

        return CompletionCompleteRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => CompletionCompleteRequest::getMethod(),
            'id' => 'test-completion-'.uniqid(),
            'params' => $params,
        ]);
    }
}
