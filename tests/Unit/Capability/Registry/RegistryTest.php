<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry;

use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegistryTest extends TestCase
{
    private Registry $registry;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new Registry(null, $this->logger);
    }

    public function testRegisterToolWithManualFlag(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = fn () => 'result';

        $this->registry->registerTool($tool, $handler, true);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testRegisterToolIgnoresDiscoveredWhenManualExists(): void
    {
        $manualTool = $this->createValidTool('test_tool');
        $discoveredTool = $this->createValidTool('test_tool');

        $this->registry->registerTool($manualTool, fn () => 'manual', true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered tool "test_tool" as it conflicts with a manually registered one.');

        $this->registry->registerTool($discoveredTool, fn () => 'discovered', false);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testRegisterToolOverridesDiscoveredWithManual(): void
    {
        $discoveredTool = $this->createValidTool('test_tool');
        $manualTool = $this->createValidTool('test_tool');

        $this->registry->registerTool($discoveredTool, fn () => 'discovered', false);
        $this->registry->registerTool($manualTool, fn () => 'manual', true);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testRegisterResourceWithManualFlag(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn () => 'content';

        $this->registry->registerResource($resource, $handler, true);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertTrue($resourceRef->isManual);
    }

    public function testRegisterResourceIgnoresDiscoveredWhenManualExists(): void
    {
        $manualResource = $this->createValidResource('test://resource');
        $discoveredResource = $this->createValidResource('test://resource');

        $this->registry->registerResource($manualResource, fn () => 'manual', true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered resource "test://resource" as it conflicts with a manually registered one.');

        $this->registry->registerResource($discoveredResource, fn () => 'discovered', false);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertTrue($resourceRef->isManual);
    }

    public function testRegisterResourceTemplateWithCompletionProviders(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $completionProviders = ['id' => EnumCompletionProvider::class];

        $this->registry->registerResourceTemplate($template, fn () => 'content', $completionProviders);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertEquals($completionProviders, $templateRef->completionProviders);
    }

    public function testRegisterResourceTemplateIgnoresDiscoveredWhenManualExists(): void
    {
        $manualTemplate = $this->createValidResourceTemplate('test://{id}');
        $discoveredTemplate = $this->createValidResourceTemplate('test://{id}');

        $this->registry->registerResourceTemplate($manualTemplate, fn () => 'manual', [], true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered template "test://{id}" as it conflicts with a manually registered one.');

        $this->registry->registerResourceTemplate($discoveredTemplate, fn () => 'discovered', [], false);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertTrue($templateRef->isManual);
    }

    public function testRegisterPromptWithCompletionProviders(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $completionProviders = ['param' => EnumCompletionProvider::class];

        $this->registry->registerPrompt($prompt, fn () => [], $completionProviders);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertEquals($completionProviders, $promptRef->completionProviders);
    }

    public function testRegisterPromptIgnoresDiscoveredWhenManualExists(): void
    {
        $manualPrompt = $this->createValidPrompt('test_prompt');
        $discoveredPrompt = $this->createValidPrompt('test_prompt');

        $this->registry->registerPrompt($manualPrompt, fn () => 'manual', [], true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered prompt "test_prompt" as it conflicts with a manually registered one.');

        $this->registry->registerPrompt($discoveredPrompt, fn () => 'discovered', [], false);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertTrue($promptRef->isManual);
    }

    public function testClearRemovesOnlyDiscoveredElements(): void
    {
        $manualTool = $this->createValidTool('manual_tool');
        $discoveredTool = $this->createValidTool('discovered_tool');
        $manualResource = $this->createValidResource('test://manual');
        $discoveredResource = $this->createValidResource('test://discovered');
        $manualPrompt = $this->createValidPrompt('manual_prompt');
        $discoveredPrompt = $this->createValidPrompt('discovered_prompt');
        $manualTemplate = $this->createValidResourceTemplate('manual://{id}');
        $discoveredTemplate = $this->createValidResourceTemplate('discovered://{id}');

        // Register manual elements directly
        $this->registry->registerTool($manualTool, fn () => 'manual', true);
        $this->registry->registerResource($manualResource, fn () => 'manual', true);
        $this->registry->registerPrompt($manualPrompt, fn () => [], [], true);
        $this->registry->registerResourceTemplate($manualTemplate, fn () => 'manual', [], true);

        // Import discovered elements via setDiscoveryState
        $this->registry->setDiscoveryState(new DiscoveryState(
            tools: ['discovered_tool' => new ToolReference($discoveredTool, fn () => 'discovered', false)],
            resources: ['test://discovered' => new ResourceReference($discoveredResource, fn () => 'discovered', false)],
            prompts: ['discovered_prompt' => new PromptReference($discoveredPrompt, fn () => [], false)],
            resourceTemplates: ['discovered://{id}' => new ResourceTemplateReference($discoveredTemplate, fn () => 'discovered', false)],
        ));

        $this->registry->clear();

        // Manual elements survive
        $this->assertNotNull($this->registry->getTool('manual_tool'));
        $this->assertNotNull($this->registry->getResource('test://manual'));
        $this->assertNotNull($this->registry->getPrompt('manual_prompt'));
        $this->assertNotNull($this->registry->getResourceTemplate('manual://{id}'));

        // Discovered elements are gone
        $this->assertException(ToolNotFoundException::class, fn () => $this->registry->getTool('discovered_tool'));
        $this->assertException(ResourceNotFoundException::class, fn () => $this->registry->getResource('test://discovered', false));
        $this->assertException(PromptNotFoundException::class, fn () => $this->registry->getPrompt('discovered_prompt'));
        $this->assertException(ResourceNotFoundException::class, fn () => $this->registry->getResourceTemplate('discovered://{id}'));
    }

    public function testRegisterToolHandlesStringHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = 'TestClass::testMethod';

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals($handler, $toolRef->handler);
    }

    public function testRegisterToolHandlesArrayHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = ['TestClass', 'testMethod'];

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals($handler, $toolRef->handler);
    }

    public function testRegisterResourceHandlesCallableHandler(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testMultipleRegistrationsOfSameElementWithSameType(): void
    {
        $tool1 = $this->createValidTool('test_tool');
        $tool2 = $this->createValidTool('test_tool');

        $this->registry->registerTool($tool1, fn () => 'first', false);
        $this->registry->registerTool($tool2, fn () => 'second', false);

        // Second registration should override the first
        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals('second', ($toolRef->handler)());
    }

    public function testClearPreservesDynamicallyRegisteredElements(): void
    {
        // 1. Register a manual tool
        $manualTool = $this->createValidTool('manual_tool');
        $this->registry->registerTool($manualTool, fn () => 'manual', true);

        // 2. Import discovered tools via setDiscoveryState
        $discoveredTool = $this->createValidTool('discovered_tool');
        $this->registry->setDiscoveryState(new DiscoveryState(
            tools: ['discovered_tool' => new ToolReference($discoveredTool, fn () => 'discovered', false)],
        ));

        // 3. Register a dynamic tool (not manual, not discovered)
        $dynamicTool = $this->createValidTool('dynamic_tool');
        $this->registry->registerTool($dynamicTool, fn () => 'dynamic', false);

        // 4. Restore discovery state again (simulates next HTTP request)
        $discoveredTool2 = $this->createValidTool('discovered_tool_v2');
        $this->registry->setDiscoveryState(new DiscoveryState(
            tools: ['discovered_tool_v2' => new ToolReference($discoveredTool2, fn () => 'discovered_v2', false)],
        ));

        // Manual tool survives
        $this->assertNotNull($this->registry->getTool('manual_tool'));
        // Dynamic tool survives
        $this->assertNotNull($this->registry->getTool('dynamic_tool'));
        // Old discovered tool is gone
        $this->assertException(ToolNotFoundException::class, fn () => $this->registry->getTool('discovered_tool'));
        // New discovered tool is present
        $this->assertNotNull($this->registry->getTool('discovered_tool_v2'));
    }

    public function testGetDiscoveryStateExcludesDynamicTools(): void
    {
        // Import discovered tool via setDiscoveryState
        $discoveredTool = $this->createValidTool('discovered_tool');
        $this->registry->setDiscoveryState(new DiscoveryState(
            tools: ['discovered_tool' => new ToolReference($discoveredTool, fn () => 'discovered', false)],
        ));

        // Register a dynamic tool
        $dynamicTool = $this->createValidTool('dynamic_tool');
        $this->registry->registerTool($dynamicTool, fn () => 'dynamic', false);

        // Register a manual tool
        $manualTool = $this->createValidTool('manual_tool');
        $this->registry->registerTool($manualTool, fn () => 'manual', true);

        $state = $this->registry->getDiscoveryState();

        $this->assertArrayHasKey('discovered_tool', $state->getTools());
        $this->assertArrayNotHasKey('dynamic_tool', $state->getTools());
        $this->assertArrayNotHasKey('manual_tool', $state->getTools());
    }

    public function testSetDiscoveryStateRoundTrip(): void
    {
        // Import initial discovered state
        $tool = $this->createValidTool('round_trip_tool');
        $resource = $this->createValidResource('test://round-trip');
        $prompt = $this->createValidPrompt('round_trip_prompt');
        $template = $this->createValidResourceTemplate('round-trip://{id}');

        $initialState = new DiscoveryState(
            tools: ['round_trip_tool' => new ToolReference($tool, fn () => 'result', false)],
            resources: ['test://round-trip' => new ResourceReference($resource, fn () => 'content', false)],
            prompts: ['round_trip_prompt' => new PromptReference($prompt, fn () => [], false)],
            resourceTemplates: ['round-trip://{id}' => new ResourceTemplateReference($template, fn () => 'tpl', false)],
        );

        $this->registry->setDiscoveryState($initialState);

        // Round-trip: get and set again
        $exportedState = $this->registry->getDiscoveryState();
        $this->registry->setDiscoveryState($exportedState);

        // All elements still present
        $this->assertNotNull($this->registry->getTool('round_trip_tool'));
        $this->assertNotNull($this->registry->getResource('test://round-trip'));
        $this->assertNotNull($this->registry->getPrompt('round_trip_prompt'));
        $this->assertNotNull($this->registry->getResourceTemplate('round-trip://{id}'));

        // Exported state matches
        $reExportedState = $this->registry->getDiscoveryState();
        $this->assertCount(\count($exportedState->getTools()), $reExportedState->getTools());
        $this->assertCount(\count($exportedState->getResources()), $reExportedState->getResources());
        $this->assertCount(\count($exportedState->getPrompts()), $reExportedState->getPrompts());
        $this->assertCount(\count($exportedState->getResourceTemplates()), $reExportedState->getResourceTemplates());
    }

    public function testSetDiscoveryStateDoesNotOverwriteManualOrDynamicTools(): void
    {
        // Register a manual tool
        $manualTool = $this->createValidTool('conflict_tool');
        $this->registry->registerTool($manualTool, fn () => 'manual_result', true);

        // Register a dynamic tool
        $dynamicTool = $this->createValidTool('dynamic_conflict');
        $this->registry->registerTool($dynamicTool, fn () => 'dynamic_result', false);

        // Try to import discovered tools with same names
        $discoveredConflict = $this->createValidTool('conflict_tool');
        $discoveredDynConflict = $this->createValidTool('dynamic_conflict');
        $this->registry->setDiscoveryState(new DiscoveryState(
            tools: [
                'conflict_tool' => new ToolReference($discoveredConflict, fn () => 'discovered_result', false),
                'dynamic_conflict' => new ToolReference($discoveredDynConflict, fn () => 'discovered_dyn_result', false),
            ],
        ));

        // Manual tool preserved with original handler
        $manualRef = $this->registry->getTool('conflict_tool');
        $this->assertTrue($manualRef->isManual);
        $this->assertEquals('manual_result', ($manualRef->handler)());

        // Dynamic tool preserved with original handler
        $dynamicRef = $this->registry->getTool('dynamic_conflict');
        $this->assertEquals('dynamic_result', ($dynamicRef->handler)());
    }

    private function createValidTool(string $name): Tool
    {
        return new Tool(
            name: $name,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'param' => ['type' => 'string'],
                ],
                'required' => null,
            ],
            description: "Test tool: {$name}",
            annotations: null,
        );
    }

    private function createValidResource(string $uri): Resource
    {
        return new Resource(
            uri: $uri,
            name: 'test_resource',
            description: 'Test resource',
            mimeType: 'text/plain',
        );
    }

    private function createValidResourceTemplate(string $uriTemplate): ResourceTemplate
    {
        return new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: 'test_template',
            description: 'Test resource template',
            mimeType: 'text/plain',
        );
    }

    private function createValidPrompt(string $name): Prompt
    {
        return new Prompt(
            name: $name,
            description: "Test prompt: {$name}",
            arguments: [],
        );
    }

    private function assertException(string $exceptionClass, callable $callback): void
    {
        try {
            $callback();
            $this->fail(\sprintf('Expected exception %s was not thrown.', $exceptionClass));
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exceptionClass, $e);
        }
    }
}
