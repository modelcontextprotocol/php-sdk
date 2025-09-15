<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Discovery;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Prompt\Completion\EnumCompletionProvider;
use Mcp\Capability\Prompt\Completion\ListCompletionProvider;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Tests\Capability\Attribute\CompletionProviderFixture;
use Mcp\Tests\Capability\Discovery\Fixtures\DiscoverableToolHandler;
use Mcp\Tests\Capability\Discovery\Fixtures\InvocablePromptFixture;
use Mcp\Tests\Capability\Discovery\Fixtures\InvocableResourceFixture;
use Mcp\Tests\Capability\Discovery\Fixtures\InvocableResourceTemplateFixture;
use Mcp\Tests\Capability\Discovery\Fixtures\InvocableToolFixture;
use PHPUnit\Framework\TestCase;

class DiscoveryTest extends TestCase
{
    private Registry $registry;
    private Discoverer $discoverer;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->discoverer = new Discoverer($this->registry);
    }

    public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles()
    {
        $this->discoverer->discover(__DIR__, ['Fixtures']);

        $tools = $this->registry->getTools();
        $this->assertCount(4, $tools);

        $greetUserTool = $this->registry->getTool('greet_user');
        $this->assertInstanceOf(ToolReference::class, $greetUserTool);
        $this->assertFalse($greetUserTool->isManual);
        $this->assertEquals('greet_user', $greetUserTool->tool->name);
        $this->assertEquals('Greets a user by name.', $greetUserTool->tool->description);
        $this->assertEquals([DiscoverableToolHandler::class, 'greet'], $greetUserTool->handler);
        $this->assertArrayHasKey('name', $greetUserTool->tool->inputSchema['properties'] ?? []);

        $repeatActionTool = $this->registry->getTool('repeatAction');
        $this->assertInstanceOf(ToolReference::class, $repeatActionTool);
        $this->assertEquals('A tool with more complex parameters and inferred name/description.', $repeatActionTool->tool->description);
        $this->assertTrue($repeatActionTool->tool->annotations->readOnlyHint);
        $this->assertEquals(['count', 'loudly', 'mode'], array_keys($repeatActionTool->tool->inputSchema['properties'] ?? []));

        $invokableCalcTool = $this->registry->getTool('InvokableCalculator');
        $this->assertInstanceOf(ToolReference::class, $invokableCalcTool);
        $this->assertFalse($invokableCalcTool->isManual);
        $this->assertEquals([InvocableToolFixture::class, '__invoke'], $invokableCalcTool->handler);

        $this->assertNull($this->registry->getTool('private_tool_should_be_ignored'));
        $this->assertNull($this->registry->getTool('protected_tool_should_be_ignored'));
        $this->assertNull($this->registry->getTool('static_tool_should_be_ignored'));

        $resources = $this->registry->getResources();
        $this->assertCount(3, $resources);

        $appVersionRes = $this->registry->getResource('app://info/version');
        $this->assertInstanceOf(ResourceReference::class, $appVersionRes);
        $this->assertFalse($appVersionRes->isManual);
        $this->assertEquals('app_version', $appVersionRes->schema->name);
        $this->assertEquals('text/plain', $appVersionRes->schema->mimeType);

        $invokableStatusRes = $this->registry->getResource('invokable://config/status');
        $this->assertInstanceOf(ResourceReference::class, $invokableStatusRes);
        $this->assertFalse($invokableStatusRes->isManual);
        $this->assertEquals([InvocableResourceFixture::class, '__invoke'], $invokableStatusRes->handler);

        $prompts = $this->registry->getPrompts();
        $this->assertCount(4, $prompts);

        $storyPrompt = $this->registry->getPrompt('creative_story_prompt');
        $this->assertInstanceOf(PromptReference::class, $storyPrompt);
        $this->assertFalse($storyPrompt->isManual);
        $this->assertCount(2, $storyPrompt->prompt->arguments);
        $this->assertEquals(CompletionProviderFixture::class, $storyPrompt->completionProviders['genre']);

        $simplePrompt = $this->registry->getPrompt('simpleQuestionPrompt');
        $this->assertInstanceOf(PromptReference::class, $simplePrompt);
        $this->assertFalse($simplePrompt->isManual);

        $invokableGreeter = $this->registry->getPrompt('InvokableGreeterPrompt');
        $this->assertInstanceOf(PromptReference::class, $invokableGreeter);
        $this->assertFalse($invokableGreeter->isManual);
        $this->assertEquals([InvocablePromptFixture::class, '__invoke'], $invokableGreeter->handler);

        $contentCreatorPrompt = $this->registry->getPrompt('content_creator');
        $this->assertInstanceOf(PromptReference::class, $contentCreatorPrompt);
        $this->assertFalse($contentCreatorPrompt->isManual);
        $this->assertCount(3, $contentCreatorPrompt->completionProviders);

        $templates = $this->registry->getResourceTemplates();
        $this->assertCount(4, $templates);

        $productTemplate = $this->registry->getResourceTemplate('product://{region}/details/{productId}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $productTemplate);
        $this->assertFalse($productTemplate->isManual);
        $this->assertEquals('product_details_template', $productTemplate->resourceTemplate->name);
        $this->assertEquals(CompletionProviderFixture::class, $productTemplate->completionProviders['region']);
        $this->assertEqualsCanonicalizing(['region', 'productId'], $productTemplate->getVariableNames());

        $invokableUserTemplate = $this->registry->getResourceTemplate('invokable://user-profile/{userId}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $invokableUserTemplate);
        $this->assertFalse($invokableUserTemplate->isManual);
        $this->assertEquals([InvocableResourceTemplateFixture::class, '__invoke'], $invokableUserTemplate->handler);
    }

    public function testDoesNotDiscoverElementsFromExcludedDirectories()
    {
        $this->discoverer->discover(__DIR__, ['Fixtures']);
        $this->assertInstanceOf(ToolReference::class, $this->registry->getTool('hidden_subdir_tool'));

        $this->registry->clear();

        $this->discoverer->discover(__DIR__, ['Fixtures'], ['SubDir']);
        $this->assertNull($this->registry->getTool('hidden_subdir_tool'));
    }

    public function testHandlesEmptyDirectoriesOrDirectoriesWithNoPhpFiles()
    {
        $this->discoverer->discover(__DIR__, ['EmptyDir']);
        $this->assertEmpty($this->registry->getTools());
    }

    public function testCorrectlyInfersNamesAndDescriptionsFromMethodsOrClassesIfNotSetInAttribute()
    {
        $this->discoverer->discover(__DIR__, ['Fixtures']);

        $repeatActionTool = $this->registry->getTool('repeatAction');
        $this->assertEquals('repeatAction', $repeatActionTool->tool->name);
        $this->assertEquals('A tool with more complex parameters and inferred name/description.', $repeatActionTool->tool->description);

        $simplePrompt = $this->registry->getPrompt('simpleQuestionPrompt');
        $this->assertEquals('simpleQuestionPrompt', $simplePrompt->prompt->name);
        $this->assertNull($simplePrompt->prompt->description);

        $invokableCalc = $this->registry->getTool('InvokableCalculator');
        $this->assertEquals('InvokableCalculator', $invokableCalc->tool->name);
        $this->assertEquals('An invokable calculator tool.', $invokableCalc->tool->description);
    }

    public function testDiscoversEnhancedCompletionProvidersWithValuesAndEnumAttributes()
    {
        $this->discoverer->discover(__DIR__, ['Fixtures']);

        $contentPrompt = $this->registry->getPrompt('content_creator');
        $this->assertInstanceOf(PromptReference::class, $contentPrompt);
        $this->assertCount(3, $contentPrompt->completionProviders);

        $typeProvider = $contentPrompt->completionProviders['type'];
        $this->assertInstanceOf(ListCompletionProvider::class, $typeProvider);

        $statusProvider = $contentPrompt->completionProviders['status'];
        $this->assertInstanceOf(EnumCompletionProvider::class, $statusProvider);

        $priorityProvider = $contentPrompt->completionProviders['priority'];
        $this->assertInstanceOf(EnumCompletionProvider::class, $priorityProvider);

        $contentTemplate = $this->registry->getResourceTemplate('content://{category}/{slug}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $contentTemplate);
        $this->assertCount(1, $contentTemplate->completionProviders);

        $categoryProvider = $contentTemplate->completionProviders['category'];
        $this->assertInstanceOf(ListCompletionProvider::class, $categoryProvider);
    }
}
