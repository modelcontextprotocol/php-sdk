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
use PHPUnit\Framework\TestCase;

class RegistryProviderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry();
    }

    public function testGetToolReturnsRegisteredTool(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = fn () => 'result';

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertInstanceOf(ToolReference::class, $toolRef);
        $this->assertEquals($tool->name, $toolRef->tool->name);
        $this->assertEquals($handler, $toolRef->handler);
        $this->assertFalse($toolRef->isManual);
    }

    public function testGetToolThrowsExceptionForUnregisteredTool(): void
    {
        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessage('Tool not found: "non_existent_tool".');

        $this->registry->getTool('non_existent_tool');
    }

    public function testGetResourceReturnsRegisteredResource(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->schema->uri);
        $this->assertEquals($handler, $resourceRef->handler);
        $this->assertFalse($resourceRef->isManual);
    }

    public function testGetResourceThrowsExceptionForUnregisteredResource(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://non_existent".');

        $this->registry->getResource('test://non_existent');
    }

    public function testGetResourceMatchesResourceTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals($template->uriTemplate, $resourceRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testGetResourceWithIncludeTemplatesFalseThrowsException(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://123".');

        $this->registry->getResource('test://123', false);
    }

    public function testGetResourcePrefersDirectResourceOverTemplate(): void
    {
        $resource = $this->createValidResource('test://123');
        $resourceHandler = fn () => 'direct resource';

        $template = $this->createValidResourceTemplate('test://{id}');
        $templateHandler = fn (string $id) => "template for {$id}";

        $this->registry->registerResource($resource, $resourceHandler);
        $this->registry->registerResourceTemplate($template, $templateHandler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->schema->uri);
    }

    public function testGetResourceTemplateReturnsRegisteredTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $templateRef);
        $this->assertEquals($template->uriTemplate, $templateRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $templateRef->handler);
        $this->assertFalse($templateRef->isManual);
    }

    public function testGetResourceTemplateThrowsExceptionForUnregisteredTemplate(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://{non_existent}".');

        $this->registry->getResourceTemplate('test://{non_existent}');
    }

    public function testGetPromptReturnsRegisteredPrompt(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = fn () => ['role' => 'user', 'content' => 'test message'];

        $this->registry->registerPrompt($prompt, $handler);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertInstanceOf(PromptReference::class, $promptRef);
        $this->assertEquals($prompt->name, $promptRef->prompt->name);
        $this->assertEquals($handler, $promptRef->handler);
        $this->assertFalse($promptRef->isManual);
    }

    public function testGetPromptThrowsExceptionForUnregisteredPrompt(): void
    {
        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessage('Prompt not found: "non_existent_prompt".');

        $this->registry->getPrompt('non_existent_prompt');
    }

    public function testGetToolsReturnsAllRegisteredTools(): void
    {
        $tool1 = $this->createValidTool('tool1');
        $tool2 = $this->createValidTool('tool2');

        $this->registry->registerTool($tool1, fn () => 'result1');
        $this->registry->registerTool($tool2, fn () => 'result2');

        $tools = $this->registry->getTools();
        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('tool1', $tools->references);
        $this->assertArrayHasKey('tool2', $tools->references);
        $this->assertInstanceOf(Tool::class, $tools->references['tool1']);
        $this->assertInstanceOf(Tool::class, $tools->references['tool2']);
    }

    public function testGetResourcesReturnsAllRegisteredResources(): void
    {
        $resource1 = $this->createValidResource('test://resource1');
        $resource2 = $this->createValidResource('test://resource2');

        $this->registry->registerResource($resource1, fn () => 'content1');
        $this->registry->registerResource($resource2, fn () => 'content2');

        $resources = $this->registry->getResources();
        $this->assertCount(2, $resources);
        $this->assertArrayHasKey('test://resource1', $resources->references);
        $this->assertArrayHasKey('test://resource2', $resources->references);
        $this->assertInstanceOf(Resource::class, $resources->references['test://resource1']);
        $this->assertInstanceOf(Resource::class, $resources->references['test://resource2']);
    }

    public function testGetPromptsReturnsAllRegisteredPrompts(): void
    {
        $prompt1 = $this->createValidPrompt('prompt1');
        $prompt2 = $this->createValidPrompt('prompt2');

        $this->registry->registerPrompt($prompt1, fn () => []);
        $this->registry->registerPrompt($prompt2, fn () => []);

        $prompts = $this->registry->getPrompts();
        $this->assertCount(2, $prompts);
        $this->assertArrayHasKey('prompt1', $prompts->references);
        $this->assertArrayHasKey('prompt2', $prompts->references);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt1']);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt2']);
    }

    public function testGetResourceTemplatesReturnsAllRegisteredTemplates(): void
    {
        $template1 = $this->createValidResourceTemplate('test1://{id}');
        $template2 = $this->createValidResourceTemplate('test2://{category}');

        $this->registry->registerResourceTemplate($template1, fn () => 'content1');
        $this->registry->registerResourceTemplate($template2, fn () => 'content2');

        $templates = $this->registry->getResourceTemplates();
        $this->assertCount(2, $templates);
        $this->assertArrayHasKey('test1://{id}', $templates->references);
        $this->assertArrayHasKey('test2://{category}', $templates->references);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test1://{id}']);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test2://{category}']);
    }

    public function testHasElementsReturnsFalseForEmptyRegistry(): void
    {
        $this->assertFalse($this->registry->hasElements());
    }

    public function testHasElementsReturnsTrueWhenToolIsRegistered(): void
    {
        $tool = $this->createValidTool('test_tool');
        $this->registry->registerTool($tool, fn () => 'result');

        $this->assertTrue($this->registry->hasElements());
    }

    public function testHasElementsReturnsTrueWhenResourceIsRegistered(): void
    {
        $resource = $this->createValidResource('test://resource');
        $this->registry->registerResource($resource, fn () => 'content');

        $this->assertTrue($this->registry->hasElements());
    }

    public function testHasElementsReturnsTrueWhenPromptIsRegistered(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $this->registry->registerPrompt($prompt, fn () => []);

        $this->assertTrue($this->registry->hasElements());
    }

    public function testHasElementsReturnsTrueWhenResourceTemplateIsRegistered(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $this->registry->registerResourceTemplate($template, fn () => 'content');

        $this->assertTrue($this->registry->hasElements());
    }

    public function testResourceTemplateMatchingPrefersMoreSpecificMatches(): void
    {
        $specificTemplate = $this->createValidResourceTemplate('test://users/{userId}/profile');
        $genericTemplate = $this->createValidResourceTemplate('test://users/{userId}');

        $this->registry->registerResourceTemplate($genericTemplate, fn () => 'generic');
        $this->registry->registerResourceTemplate($specificTemplate, fn () => 'specific');

        // Should match the more specific template first
        $resourceRef = $this->registry->getResource('test://users/123/profile');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals('test://users/{userId}/profile', $resourceRef->resourceTemplate->uriTemplate);
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
