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
use Mcp\Capability\Registry;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
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

    public function testConstructorWithDefaults(): void
    {
        $registry = new Registry();
        $capabilities = $registry->getCapabilities();

        $this->assertInstanceOf(ServerCapabilities::class, $capabilities);
        $this->assertFalse($capabilities->toolsListChanged);
        $this->assertFalse($capabilities->resourcesListChanged);
        $this->assertFalse($capabilities->promptsListChanged);
    }

    public function testGetCapabilitiesWhenEmpty(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('No capabilities registered on server.');

        $capabilities = $this->registry->getCapabilities();

        $this->assertFalse($capabilities->tools);
        $this->assertFalse($capabilities->resources);
        $this->assertFalse($capabilities->prompts);
    }

    public function testGetCapabilitiesWhenPopulated(): void
    {
        $tool = $this->createValidTool('test_tool');
        $resource = $this->createValidResource('test://resource');
        $prompt = $this->createValidPrompt('test_prompt');
        $template = $this->createValidResourceTemplate('test://{id}');

        $this->registry->registerTool($tool, fn () => 'result');
        $this->registry->registerResource($resource, fn () => 'content');
        $this->registry->registerPrompt($prompt, fn () => []);
        $this->registry->registerResourceTemplate($template, fn () => 'template');

        $capabilities = $this->registry->getCapabilities();

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->completions);
        $this->assertFalse($capabilities->resourcesSubscribe);
        $this->assertTrue($capabilities->logging); // Logging is enabled by default
    }

    public function testSetCustomCapabilities(): void
    {
        $serverCapabilities = new ServerCapabilities(
            tools: false,
            toolsListChanged: true,
            resources: false,
            resourcesSubscribe: false,
            resourcesListChanged: false,
            prompts: false,
            promptsListChanged: false,
            logging: true,
            completions: true,
        );
        $tool = $this->createValidTool('test_tool');
        $resource = $this->createValidResource('test://resource');
        $prompt = $this->createValidPrompt('test_prompt');
        $template = $this->createValidResourceTemplate('test://{id}');

        $this->registry->registerTool($tool, fn () => 'result');
        $this->registry->registerResource($resource, fn () => 'content');
        $this->registry->registerPrompt($prompt, fn () => []);
        $this->registry->registerResourceTemplate($template, fn () => 'template');

        $this->registry->setServerCapabilities($serverCapabilities);

        $capabilities = $this->registry->getCapabilities();

        $this->assertFalse($capabilities->tools);
        $this->assertFalse($capabilities->resources);
        $this->assertFalse($capabilities->prompts);
        $this->assertTrue($capabilities->completions);
        $this->assertFalse($capabilities->resourcesSubscribe);
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->toolsListChanged);
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
            ->with("Ignoring discovered tool 'test_tool' as it conflicts with a manually registered one.");

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
            ->with("Ignoring discovered resource 'test://resource' as it conflicts with a manually registered one.");

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
            ->with("Ignoring discovered template 'test://{id}' as it conflicts with a manually registered one.");

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
            ->with("Ignoring discovered prompt 'test_prompt' as it conflicts with a manually registered one.");

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

        $this->registry->registerTool($manualTool, fn () => 'manual', true);
        $this->registry->registerTool($discoveredTool, fn () => 'discovered', false);
        $this->registry->registerResource($manualResource, fn () => 'manual', true);
        $this->registry->registerResource($discoveredResource, fn () => 'discovered', false);
        $this->registry->registerPrompt($manualPrompt, fn () => [], [], true);
        $this->registry->registerPrompt($discoveredPrompt, fn () => [], [], false);
        $this->registry->registerResourceTemplate($manualTemplate, fn () => 'manual', [], true);
        $this->registry->registerResourceTemplate($discoveredTemplate, fn () => 'discovered', [], false);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Removed 4 discovered elements from internal registry.');

        $this->registry->clear();

        $this->assertNotNull($this->registry->getTool('manual_tool'));
        $this->assertNull($this->registry->getTool('discovered_tool'));
        $this->assertNotNull($this->registry->getResource('test://manual'));
        $this->assertNull(
            $this->registry->getResource('test://discovered', false),
        ); // Don't include templates to avoid debug log
        $this->assertNotNull($this->registry->getPrompt('manual_prompt'));
        $this->assertNull($this->registry->getPrompt('discovered_prompt'));
        $this->assertNotNull($this->registry->getResourceTemplate('manual://{id}'));
        $this->assertNull($this->registry->getResourceTemplate('discovered://{id}'));
    }

    public function testClearLogsNothingWhenNoDiscoveredElements(): void
    {
        $manualTool = $this->createValidTool('manual_tool');
        $this->registry->registerTool($manualTool, fn () => 'manual', true);

        $this->logger
            ->expects($this->never())
            ->method('debug');

        $this->registry->clear();

        $this->assertNotNull($this->registry->getTool('manual_tool'));
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
}
