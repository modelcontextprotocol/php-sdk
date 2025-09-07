<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Capability\Registry;

use Mcp\Capability\DispatchableRegistry;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

class DispatchableRegistryTest extends TestCase
{
    private ReferenceRegistryInterface $referenceRegistry;
    private EventDispatcherInterface $eventDispatcher;
    private DispatchableRegistry $dispatchableRegistry;

    protected function setUp(): void
    {
        $this->referenceRegistry = $this->createMock(ReferenceRegistryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, $this->eventDispatcher);
    }

    public function testConstructorWithoutEventDispatcher(): void
    {
        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry);
        
        $this->assertInstanceOf(DispatchableRegistry::class, $dispatchableRegistry);
    }

    public function testGetCapabilitiesWithEventDispatcher(): void
    {
        $baseCapabilities = new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: true,
            resourcesListChanged: false,
            prompts: true,
            promptsListChanged: false
        );

        $this->referenceRegistry->expects($this->once())
            ->method('getCapabilities')
            ->willReturn($baseCapabilities);

        $capabilities = $this->dispatchableRegistry->getCapabilities();

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->promptsListChanged);
    }

    public function testGetCapabilitiesWithoutEventDispatcher(): void
    {
        $baseCapabilities = new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: true,
            resourcesListChanged: false,
            prompts: true,
            promptsListChanged: false
        );

        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, null);

        $this->referenceRegistry->expects($this->once())
            ->method('getCapabilities')
            ->willReturn($baseCapabilities);

        $capabilities = $dispatchableRegistry->getCapabilities();

        $this->assertTrue($capabilities->tools);
        $this->assertFalse($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resources);
        $this->assertFalse($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->prompts);
        $this->assertFalse($capabilities->promptsListChanged);
    }

    public function testRegisterToolDelegatesToReferenceRegistryAndDispatchesEvent(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = fn() => 'result';

        $this->referenceRegistry->expects($this->once())
            ->method('registerTool')
            ->with($tool, $handler, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ToolListChangedEvent::class));

        $this->dispatchableRegistry->registerTool($tool, $handler);
    }

    public function testRegisterToolWithManualFlag(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = fn() => 'result';

        $this->referenceRegistry->expects($this->once())
            ->method('registerTool')
            ->with($tool, $handler, true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ToolListChangedEvent::class));

        $this->dispatchableRegistry->registerTool($tool, $handler, true);
    }

    public function testRegisterToolWithoutEventDispatcher(): void
    {
        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, null);
        $tool = $this->createValidTool('test_tool');
        $handler = fn() => 'result';

        $this->referenceRegistry->expects($this->once())
            ->method('registerTool')
            ->with($tool, $handler, false);

        // Should not throw exception when event dispatcher is null
        $dispatchableRegistry->registerTool($tool, $handler);
    }

    public function testRegisterResourceDelegatesToReferenceRegistryAndDispatchesEvent(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResource')
            ->with($resource, $handler, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceListChangedEvent::class));

        $this->dispatchableRegistry->registerResource($resource, $handler);
    }

    public function testRegisterResourceWithManualFlag(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResource')
            ->with($resource, $handler, true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceListChangedEvent::class));

        $this->dispatchableRegistry->registerResource($resource, $handler, true);
    }

    public function testRegisterResourceWithoutEventDispatcher(): void
    {
        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, null);
        $resource = $this->createValidResource('test://resource');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResource')
            ->with($resource, $handler, false);

        $dispatchableRegistry->registerResource($resource, $handler);
    }

    public function testRegisterResourceTemplateDelegatesToReferenceRegistryAndDispatchesEvent(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn() => 'content';
        $completionProviders = ['id' => 'TestProvider'];

        $this->referenceRegistry->expects($this->once())
            ->method('registerResourceTemplate')
            ->with($template, $handler, $completionProviders, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceTemplateListChangedEvent::class));

        $this->dispatchableRegistry->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function testRegisterResourceTemplateWithDefaults(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResourceTemplate')
            ->with($template, $handler, [], false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceTemplateListChangedEvent::class));

        $this->dispatchableRegistry->registerResourceTemplate($template, $handler);
    }

    public function testRegisterResourceTemplateWithManualFlag(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResourceTemplate')
            ->with($template, $handler, [], true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceTemplateListChangedEvent::class));

        $this->dispatchableRegistry->registerResourceTemplate($template, $handler, [], true);
    }

    public function testRegisterResourceTemplateWithoutEventDispatcher(): void
    {
        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, null);
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResourceTemplate')
            ->with($template, $handler, [], false);

        $dispatchableRegistry->registerResourceTemplate($template, $handler);
    }

    public function testRegisterPromptDelegatesToReferenceRegistryAndDispatchesEvent(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = fn() => [];
        $completionProviders = ['param' => 'TestProvider'];

        $this->referenceRegistry->expects($this->once())
            ->method('registerPrompt')
            ->with($prompt, $handler, $completionProviders, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PromptListChangedEvent::class));

        $this->dispatchableRegistry->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function testRegisterPromptWithDefaults(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = fn() => [];

        $this->referenceRegistry->expects($this->once())
            ->method('registerPrompt')
            ->with($prompt, $handler, [], false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PromptListChangedEvent::class));

        $this->dispatchableRegistry->registerPrompt($prompt, $handler);
    }

    public function testRegisterPromptWithManualFlag(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = fn() => [];

        $this->referenceRegistry->expects($this->once())
            ->method('registerPrompt')
            ->with($prompt, $handler, [], true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PromptListChangedEvent::class));

        $this->dispatchableRegistry->registerPrompt($prompt, $handler, [], true);
    }

    public function testRegisterPromptWithoutEventDispatcher(): void
    {
        $dispatchableRegistry = new DispatchableRegistry($this->referenceRegistry, null);
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = fn() => [];

        $this->referenceRegistry->expects($this->once())
            ->method('registerPrompt')
            ->with($prompt, $handler, [], false);

        $dispatchableRegistry->registerPrompt($prompt, $handler);
    }

    public function testClearDelegatesToReferenceRegistry(): void
    {
        $this->referenceRegistry->expects($this->once())
            ->method('clear');

        $this->dispatchableRegistry->clear();
    }

    public function testRegisterToolHandlesStringHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = 'TestClass::testMethod';

        $this->referenceRegistry->expects($this->once())
            ->method('registerTool')
            ->with($tool, $handler, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ToolListChangedEvent::class));

        $this->dispatchableRegistry->registerTool($tool, $handler);
    }

    public function testRegisterToolHandlesArrayHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = ['TestClass', 'testMethod'];

        $this->referenceRegistry->expects($this->once())
            ->method('registerTool')
            ->with($tool, $handler, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ToolListChangedEvent::class));

        $this->dispatchableRegistry->registerTool($tool, $handler);
    }

    public function testRegisterResourceHandlesCallableHandler(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn() => 'content';

        $this->referenceRegistry->expects($this->once())
            ->method('registerResource')
            ->with($resource, $handler, false);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ResourceListChangedEvent::class));

        $this->dispatchableRegistry->registerResource($resource, $handler);
    }

    private function createValidTool(string $name): Tool
    {
        return new Tool(
            name: $name,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'param' => ['type' => 'string']
                ],
                'required' => null
            ],
            description: "Test tool: {$name}",
            annotations: null
        );
    }

    private function createValidResource(string $uri): Resource
    {
        return new Resource(
            uri: $uri,
            name: 'test_resource',
            description: 'Test resource',
            mimeType: 'text/plain'
        );
    }

    private function createValidResourceTemplate(string $uriTemplate): ResourceTemplate
    {
        return new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: 'test_template',
            description: 'Test resource template',
            mimeType: 'text/plain'
        );
    }

    private function createValidPrompt(string $name): Prompt
    {
        return new Prompt(
            name: $name,
            description: "Test prompt: {$name}",
            arguments: []
        );
    }
}
