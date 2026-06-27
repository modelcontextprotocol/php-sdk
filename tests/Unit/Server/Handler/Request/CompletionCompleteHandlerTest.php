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

use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Handler\Request\CompletionCompleteHandler;
use Mcp\Server\Session\SessionInterface;
use Mcp\Tests\Unit\Capability\Attribute\CompletionProviderFixture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    /**
     * @param array{ name: string, value: string } $argument
     */
    private function createCompletionRequest(string $uri, array $argument): CompletionCompleteRequest
    {
        return CompletionCompleteRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => CompletionCompleteRequest::getMethod(),
            'id' => 'test-completion-'.uniqid(),
            'params' => [
                'ref' => ['type' => 'ref/resource', 'uri' => $uri],
                'argument' => $argument,
            ],
        ]);
    }
}
