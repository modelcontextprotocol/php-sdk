<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability;

use Mcp\Capability\Completion\EnumCompletionProvider;
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

    public function testHasserReturnFalseForEmptyRegistry(): void
    {
        $this->assertFalse($this->registry->hasTools());
        $this->assertFalse($this->registry->hasResources());
        $this->assertFalse($this->registry->hasResourceTemplates());
        $this->assertFalse($this->registry->hasPrompts());
    }

    public function testHasToolsReturnsTrueWhenToolIsRegistered(): void
    {
        $tool = $this->createValidTool('test_tool');
        $this->registry->registerTool($tool, static fn () => 'result');

        $this->assertTrue($this->registry->hasTools());
    }

    public function testGetToolsReturnsAllRegisteredTools(): void
    {
        $tool1 = $this->createValidTool('tool1');
        $tool2 = $this->createValidTool('tool2');

        $this->registry->registerTool($tool1, static fn () => 'result1');
        $this->registry->registerTool($tool2, static fn () => 'result2');

        $tools = $this->registry->getTools();
        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('tool1', $tools->references);
        $this->assertArrayHasKey('tool2', $tools->references);
        $this->assertInstanceOf(Tool::class, $tools->references['tool1']);
        $this->assertInstanceOf(Tool::class, $tools->references['tool2']);
    }

    public function testGetToolReturnsRegisteredTool(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = static fn () => 'result';

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertInstanceOf(ToolReference::class, $toolRef);
        $this->assertEquals($tool->name, $toolRef->tool->name);
        $this->assertEquals($handler, $toolRef->handler);
        $this->assertFalse($toolRef->isManual);
    }

    public function testRegisterToolWithManualFlag(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = static fn () => 'result';

        $this->registry->registerTool($tool, $handler, true);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testRegisterToolIgnoresDiscoveredWhenManualExists(): void
    {
        $manualTool = $this->createValidTool('test_tool');
        $discoveredTool = $this->createValidTool('test_tool');

        $this->registry->registerTool($manualTool, static fn () => 'manual', true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered tool "test_tool" as it conflicts with a manually registered one.');

        $this->registry->registerTool($discoveredTool, static fn () => 'discovered');

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testRegisterToolOverridesDiscoveredWithManual(): void
    {
        $discoveredTool = $this->createValidTool('test_tool');
        $manualTool = $this->createValidTool('test_tool');

        $this->registry->registerTool($discoveredTool, static fn () => 'discovered');
        $this->registry->registerTool($manualTool, static fn () => 'manual', true);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertTrue($toolRef->isManual);
    }

    public function testGetToolThrowsExceptionForUnregisteredTool(): void
    {
        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessage('Tool not found: "non_existent_tool".');

        $this->registry->getTool('non_existent_tool');
    }

    public function testHasResourceReturnsTrueWhenResourceIsRegistered(): void
    {
        $resource = $this->createValidResource('test://resource');
        $this->registry->registerResource($resource, static fn () => 'content');

        $this->assertTrue($this->registry->hasResources());
    }

    public function testGetResourcesReturnsAllRegisteredResources(): void
    {
        $resource1 = $this->createValidResource('test://resource1');
        $resource2 = $this->createValidResource('test://resource2');

        $this->registry->registerResource($resource1, static fn () => 'content1');
        $this->registry->registerResource($resource2, static fn () => 'content2');

        $resources = $this->registry->getResources();
        $this->assertCount(2, $resources);
        $this->assertArrayHasKey('test://resource1', $resources->references);
        $this->assertArrayHasKey('test://resource2', $resources->references);
        $this->assertInstanceOf(Resource::class, $resources->references['test://resource1']);
        $this->assertInstanceOf(Resource::class, $resources->references['test://resource2']);
    }

    public function testGetResourceReturnsRegisteredResource(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = static fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->resource->uri);
        $this->assertEquals($handler, $resourceRef->handler);
        $this->assertFalse($resourceRef->isManual);
    }

    public function testRegisterResourceWithManualFlag(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = static fn () => 'content';

        $this->registry->registerResource($resource, $handler, true);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertTrue($resourceRef->isManual);
    }

    public function testRegisterResourceIgnoresDiscoveredWhenManualExists(): void
    {
        $manualResource = $this->createValidResource('test://resource');
        $discoveredResource = $this->createValidResource('test://resource');

        $this->registry->registerResource($manualResource, static fn () => 'manual', true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered resource "test://resource" as it conflicts with a manually registered one.');

        $this->registry->registerResource($discoveredResource, static fn () => 'discovered');

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertTrue($resourceRef->isManual);
    }

    public function testGetResourceThrowsExceptionForUnregisteredResource(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://non_existent".');

        $this->registry->getResource('test://non_existent');
    }

    public function testHasResourceTemplatesReturnsTrueWhenResourceTemplateIsRegistered(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $this->registry->registerResourceTemplate($template, static fn () => 'content');

        $this->assertTrue($this->registry->hasResourceTemplates());
    }

    public function testGetResourceTemplatesReturnsAllRegisteredTemplates(): void
    {
        $template1 = $this->createValidResourceTemplate('test1://{id}');
        $template2 = $this->createValidResourceTemplate('test2://{category}');

        $this->registry->registerResourceTemplate($template1, static fn () => 'content1');
        $this->registry->registerResourceTemplate($template2, static fn () => 'content2');

        $templates = $this->registry->getResourceTemplates();
        $this->assertCount(2, $templates);
        $this->assertArrayHasKey('test1://{id}', $templates->references);
        $this->assertArrayHasKey('test2://{category}', $templates->references);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test1://{id}']);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test2://{category}']);
    }

    public function testGetResourceTemplateReturnsRegisteredTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $templateRef);
        $this->assertEquals($template->uriTemplate, $templateRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $templateRef->handler);
        $this->assertFalse($templateRef->isManual);
    }

    public function testGetResourcePrefersDirectResourceOverTemplate(): void
    {
        $resource = $this->createValidResource('test://123');
        $resourceHandler = static fn () => 'direct resource';

        $template = $this->createValidResourceTemplate('test://{id}');
        $templateHandler = static fn (string $id) => "template for {$id}";

        $this->registry->registerResource($resource, $resourceHandler);
        $this->registry->registerResourceTemplate($template, $templateHandler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->resource->uri);
    }

    public function testGetResourceMatchesResourceTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals($template->uriTemplate, $resourceRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testGetResourceWithIncludeTemplatesFalseThrowsException(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://123".');

        $this->registry->getResource('test://123', false);
    }

    public function testRegisterResourceTemplateWithCompletionProviders(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $completionProviders = ['id' => EnumCompletionProvider::class];

        $this->registry->registerResourceTemplate($template, static fn () => 'content', $completionProviders);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertEquals($completionProviders, $templateRef->completionProviders);
    }

    public function testRegisterResourceTemplateIgnoresDiscoveredWhenManualExists(): void
    {
        $manualTemplate = $this->createValidResourceTemplate('test://{id}');
        $discoveredTemplate = $this->createValidResourceTemplate('test://{id}');

        $this->registry->registerResourceTemplate($manualTemplate, static fn () => 'manual', [], true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered template "test://{id}" as it conflicts with a manually registered one.');

        $this->registry->registerResourceTemplate($discoveredTemplate, static fn () => 'discovered');

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertTrue($templateRef->isManual);
    }

    public function testResourceTemplateMatchingPrefersMoreSpecificMatches(): void
    {
        $specificTemplate = $this->createValidResourceTemplate('test://users/{userId}/profile');
        $genericTemplate = $this->createValidResourceTemplate('test://users/{userId}');

        $this->registry->registerResourceTemplate($genericTemplate, static fn () => 'generic');
        $this->registry->registerResourceTemplate($specificTemplate, static fn () => 'specific');

        // Should match the more specific template first
        $resourceRef = $this->registry->getResource('test://users/123/profile');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals('test://users/{userId}/profile', $resourceRef->resourceTemplate->uriTemplate);
    }

    public function testGetResourceTemplateThrowsExceptionForUnregisteredTemplate(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://{non_existent}".');

        $this->registry->getResourceTemplate('test://{non_existent}');
    }

    public function testHasPromptsReturnsTrueWhenPromptIsRegistered(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $this->registry->registerPrompt($prompt, static fn () => []);

        $this->assertTrue($this->registry->hasPrompts());
    }

    public function testGetPromptsReturnsAllRegisteredPrompts(): void
    {
        $prompt1 = $this->createValidPrompt('prompt1');
        $prompt2 = $this->createValidPrompt('prompt2');

        $this->registry->registerPrompt($prompt1, static fn () => []);
        $this->registry->registerPrompt($prompt2, static fn () => []);

        $prompts = $this->registry->getPrompts();
        $this->assertCount(2, $prompts);
        $this->assertArrayHasKey('prompt1', $prompts->references);
        $this->assertArrayHasKey('prompt2', $prompts->references);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt1']);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt2']);
    }

    public function testGetPromptReturnsRegisteredPrompt(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = static fn () => ['role' => 'user', 'content' => 'test message'];

        $this->registry->registerPrompt($prompt, $handler);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertInstanceOf(PromptReference::class, $promptRef);
        $this->assertEquals($prompt->name, $promptRef->prompt->name);
        $this->assertEquals($handler, $promptRef->handler);
        $this->assertFalse($promptRef->isManual);
    }

    public function testRegisterPromptWithCompletionProviders(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $completionProviders = ['param' => EnumCompletionProvider::class];

        $this->registry->registerPrompt($prompt, static fn () => [], $completionProviders);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertEquals($completionProviders, $promptRef->completionProviders);
    }

    public function testRegisterPromptIgnoresDiscoveredWhenManualExists(): void
    {
        $manualPrompt = $this->createValidPrompt('test_prompt');
        $discoveredPrompt = $this->createValidPrompt('test_prompt');

        $this->registry->registerPrompt($manualPrompt, static fn () => 'manual', [], true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Ignoring discovered prompt "test_prompt" as it conflicts with a manually registered one.');

        $this->registry->registerPrompt($discoveredPrompt, static fn () => 'discovered');

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertTrue($promptRef->isManual);
    }

    public function testGetPromptThrowsExceptionForUnregisteredPrompt(): void
    {
        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessage('Prompt not found: "non_existent_prompt".');

        $this->registry->getPrompt('non_existent_prompt');
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

        $this->registry->registerTool($manualTool, static fn () => 'manual', true);
        $this->registry->registerTool($discoveredTool, static fn () => 'discovered');
        $this->registry->registerResource($manualResource, static fn () => 'manual', true);
        $this->registry->registerResource($discoveredResource, static fn () => 'discovered');
        $this->registry->registerPrompt($manualPrompt, static fn () => [], [], true);
        $this->registry->registerPrompt($discoveredPrompt, static fn () => []);
        $this->registry->registerResourceTemplate($manualTemplate, static fn () => 'manual', [], true);
        $this->registry->registerResourceTemplate($discoveredTemplate, static fn () => 'discovered');

        // Test that all elements exist
        $this->registry->getTool('manual_tool');
        $this->registry->getResource('test://manual');
        $this->registry->getPrompt('manual_prompt');
        $this->registry->getResourceTemplate('manual://{id}');
        $this->registry->getTool('discovered_tool');
        $this->registry->getResource('test://discovered');
        $this->registry->getPrompt('discovered_prompt');
        $this->registry->getResourceTemplate('discovered://{id}');

        $this->registry->clear();

        // Manual elements should still exist
        $this->registry->getTool('manual_tool');
        $this->registry->getResource('test://manual');
        $this->registry->getPrompt('manual_prompt');
        $this->registry->getResourceTemplate('manual://{id}');

        // Test that all discovered elements throw exceptions
        $this->expectException(ToolNotFoundException::class);
        $this->registry->getTool('discovered_tool');

        $this->expectException(ResourceNotFoundException::class);
        $this->registry->getResource('test://discovered');

        $this->expectException(PromptNotFoundException::class);
        $this->registry->getPrompt('discovered_prompt');

        $this->expectException(ResourceNotFoundException::class);
        $this->registry->getResourceTemplate('discovered://{id}');
    }

    public function testClearLogsNothingWhenNoDiscoveredElements(): void
    {
        $manualTool = $this->createValidTool('manual_tool');
        $this->registry->registerTool($manualTool, static fn () => 'manual', true);

        $this->logger
            ->expects($this->never())
            ->method('debug');

        $this->registry->clear();

        $this->registry->getTool('manual_tool');
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
        $handler = static fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testMultipleRegistrationsOfSameElementWithSameType(): void
    {
        $tool1 = $this->createValidTool('test_tool');
        $tool2 = $this->createValidTool('test_tool');

        $this->registry->registerTool($tool1, static fn () => 'first');
        $this->registry->registerTool($tool2, static fn () => 'second');

        // Second registration should override the first
        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals('second', ($toolRef->handler)());
    }

    public function testExtractStructuredContentReturnsNullWhenOutputSchemaIsNull(): void
    {
        $tool = $this->createValidTool('test_tool', null);
        $this->registry->registerTool($tool, static fn () => 'result');

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertNull($toolRef->extractStructuredContent('result'));
    }

    public function testExtractStructuredContentReturnsArrayMatchingSchema(): void
    {
        $tool = $this->createValidTool('test_tool', [
            'type' => 'object',
            'properties' => [
                'param' => ['type' => 'string'],
            ],
            'required' => ['param'],
        ]);
        $this->registry->registerTool($tool, static fn () => [
            'param' => 'test',
        ]);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals([
            'param' => 'test',
        ], $toolRef->extractStructuredContent([
            'param' => 'test',
        ]));
    }

    public function testExtractStructuredContentReturnsArrayDirectlyForAdditionalProperties(): void
    {
        $tool = $this->createValidTool('test_tool', [
            'type' => 'object',
            'additionalProperties' => true,
        ]);
        $this->registry->registerTool($tool, static fn () => ['success' => true, 'message' => 'done']);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals(['success' => true, 'message' => 'done'], $toolRef->extractStructuredContent(['success' => true, 'message' => 'done']));
    }

    public function testExtractStructuredContentReturnsArrayDirectlyForArrayOutputSchema(): void
    {
        // Arrange
        $outputSchema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'foo' => [
                        'type' => 'string',
                        'description' => 'A static value',
                    ],
                ],
                'required' => ['foo'],
            ],
        ];

        $tool = $this->createValidTool('list_static_data', $outputSchema);
        $toolReturnValue = [
            ['foo' => 'bar'],
            ['foo' => 'bar'],
            ['foo' => 'bar'],
            ['foo' => 'bar'],
        ];

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        // Act
        $toolRef = $this->registry->getTool('list_static_data');
        $structuredContent = $toolRef->extractStructuredContent($toolReturnValue);

        // Assert
        $this->assertNotNull($structuredContent);
        $this->assertCount(4, $structuredContent);
        $this->assertEquals([
            ['foo' => 'bar'],
            ['foo' => 'bar'],
            ['foo' => 'bar'],
            ['foo' => 'bar'],
        ], $structuredContent);
    }

    private function createValidTool(string $name, ?array $outputSchema = null): Tool
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
            icons: null,
            meta: null,
            outputSchema: $outputSchema
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
}
