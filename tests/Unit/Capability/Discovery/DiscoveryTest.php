<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Tests\Unit\Capability\Attribute\CompletionProviderFixture;
use Mcp\Tests\Unit\Capability\Discovery\Fixtures\DiscoverableToolHandler;
use Mcp\Tests\Unit\Capability\Discovery\Fixtures\InvocablePromptFixture;
use Mcp\Tests\Unit\Capability\Discovery\Fixtures\InvocableResourceFixture;
use Mcp\Tests\Unit\Capability\Discovery\Fixtures\InvocableResourceTemplateFixture;
use Mcp\Tests\Unit\Capability\Discovery\Fixtures\InvocableToolFixture;
use PHPUnit\Framework\TestCase;

class DiscoveryTest extends TestCase
{
    private Discoverer $discoverer;

    protected function setUp(): void
    {
        $this->discoverer = new Discoverer();
    }

    public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles()
    {
        $discovery = $this->discoverer->discover(__DIR__, ['Fixtures']);

        $tools = $discovery->getTools();
        $this->assertCount(4, $tools);

        $this->assertArrayHasKey('greet_user', $tools);
        $this->assertFalse($tools['greet_user']->isManual);
        $this->assertEquals('greet_user', $tools['greet_user']->tool->name);
        $this->assertEquals('Greets a user by name.', $tools['greet_user']->tool->description);
        $this->assertEquals([DiscoverableToolHandler::class, 'greet'], $tools['greet_user']->handler);
        $this->assertArrayHasKey('name', $tools['greet_user']->tool->inputSchema['properties'] ?? []);

        $this->assertArrayHasKey('repeatAction', $tools);
        $this->assertEquals('A tool with more complex parameters and inferred name/description.', $tools['repeatAction']->tool->description);
        $this->assertTrue($tools['repeatAction']->tool->annotations->readOnlyHint);
        $this->assertEquals(['count', 'loudly', 'mode'], array_keys($tools['repeatAction']->tool->inputSchema['properties'] ?? []));

        $this->assertArrayHasKey('InvokableCalculator', $tools);
        $this->assertInstanceOf(ToolReference::class, $tools['InvokableCalculator']);
        $this->assertFalse($tools['InvokableCalculator']->isManual);
        $this->assertEquals([InvocableToolFixture::class, '__invoke'], $tools['InvokableCalculator']->handler);

        $this->assertArrayNotHasKey('private_tool_should_be_ignored', $tools);
        $this->assertArrayNotHasKey('protected_tool_should_be_ignored', $tools);
        $this->assertArrayNotHasKey('static_tool_should_be_ignored', $tools);

        $resources = $discovery->getResources();
        $this->assertCount(3, $resources);

        $this->assertArrayHasKey('app://info/version', $resources);
        $this->assertFalse($resources['app://info/version']->isManual);
        $this->assertEquals('app_version', $resources['app://info/version']->resource->name);
        $this->assertEquals('text/plain', $resources['app://info/version']->resource->mimeType);

        $this->assertArrayHasKey('invokable://config/status', $resources);
        $this->assertFalse($resources['invokable://config/status']->isManual);
        $this->assertEquals([InvocableResourceFixture::class, '__invoke'], $resources['invokable://config/status']->handler);

        $prompts = $discovery->getPrompts();
        $this->assertCount(4, $prompts);

        $this->assertArrayHasKey('creative_story_prompt', $prompts);
        $this->assertFalse($prompts['creative_story_prompt']->isManual);
        $this->assertCount(2, $prompts['creative_story_prompt']->prompt->arguments);
        $this->assertEquals(CompletionProviderFixture::class, $prompts['creative_story_prompt']->completionProviders['genre']);

        $this->assertArrayHasKey('simpleQuestionPrompt', $prompts);
        $this->assertFalse($prompts['simpleQuestionPrompt']->isManual);

        $this->assertArrayHasKey('InvokableGreeterPrompt', $prompts);
        $this->assertFalse($prompts['InvokableGreeterPrompt']->isManual);
        $this->assertEquals([InvocablePromptFixture::class, '__invoke'], $prompts['InvokableGreeterPrompt']->handler);

        $this->assertArrayHasKey('content_creator', $prompts);
        $this->assertFalse($prompts['content_creator']->isManual);
        $this->assertCount(3, $prompts['content_creator']->completionProviders);

        $templates = $discovery->getResourceTemplates();
        $this->assertCount(4, $templates);

        $this->assertArrayHasKey('product://{region}/details/{productId}', $templates);
        $this->assertFalse($templates['product://{region}/details/{productId}']->isManual);
        $this->assertEquals('product_details_template', $templates['product://{region}/details/{productId}']->resourceTemplate->name);
        $this->assertEquals(CompletionProviderFixture::class, $templates['product://{region}/details/{productId}']->completionProviders['region']);
        $this->assertEqualsCanonicalizing(['region', 'productId'], $templates['product://{region}/details/{productId}']->getVariableNames());

        $this->assertArrayHasKey('invokable://user-profile/{userId}', $templates);
        $this->assertFalse($templates['invokable://user-profile/{userId}']->isManual);
        $this->assertEquals([InvocableResourceTemplateFixture::class, '__invoke'], $templates['invokable://user-profile/{userId}']->handler);
    }

    public function testDoesNotDiscoverElementsFromExcludedDirectories()
    {
        $discovery = $this->discoverer->discover(__DIR__, ['Fixtures']);
        $this->assertArrayHasKey('hidden_subdir_tool', $discovery->getTools());

        $discovery = $this->discoverer->discover(__DIR__, ['Fixtures'], ['SubDir']);
        $this->assertArrayNotHasKey('hidden_subdir_tool', $discovery->getTools());
    }

    public function testHandlesEmptyDirectoriesOrDirectoriesWithNoPhpFiles()
    {
        $discovery = $this->discoverer->discover(__DIR__, ['EmptyDir']);

        $this->assertTrue($discovery->isEmpty());
    }

    public function testCorrectlyInfersNamesAndDescriptionsFromMethodsOrClassesIfNotSetInAttribute()
    {
        $discovery = $this->discoverer->discover(__DIR__, ['Fixtures']);

        $this->assertArrayHasKey('repeatAction', $tools = $discovery->getTools());
        $this->assertEquals('repeatAction', $tools['repeatAction']->tool->name);
        $this->assertEquals('A tool with more complex parameters and inferred name/description.', $tools['repeatAction']->tool->description);

        $this->assertArrayHasKey('simpleQuestionPrompt', $prompts = $discovery->getPrompts());
        $this->assertEquals('simpleQuestionPrompt', $prompts['simpleQuestionPrompt']->prompt->name);
        $this->assertNull($prompts['simpleQuestionPrompt']->prompt->description);

        $this->assertArrayHasKey('InvokableCalculator', $tools);
        $this->assertEquals('InvokableCalculator', $tools['InvokableCalculator']->tool->name);
        $this->assertEquals('An invokable calculator tool.', $tools['InvokableCalculator']->tool->description);
    }

    public function testDiscoversEnhancedCompletionProvidersWithValuesAndEnumAttributes()
    {
        $discovery = $this->discoverer->discover(__DIR__, ['Fixtures']);

        $this->assertArrayHasKey('content_creator', $prompts = $discovery->getPrompts());
        $this->assertCount(3, $prompts['content_creator']->completionProviders);

        $typeProvider = $prompts['content_creator']->completionProviders['type'];
        $this->assertInstanceOf(ListCompletionProvider::class, $typeProvider);

        $statusProvider = $prompts['content_creator']->completionProviders['status'];
        $this->assertInstanceOf(EnumCompletionProvider::class, $statusProvider);

        $priorityProvider = $prompts['content_creator']->completionProviders['priority'];
        $this->assertInstanceOf(EnumCompletionProvider::class, $priorityProvider);

        $this->assertArrayHasKey('content://{category}/{slug}', $templates = $discovery->getResourceTemplates());
        $this->assertCount(1, $templates['content://{category}/{slug}']->completionProviders);

        $categoryProvider = $templates['content://{category}/{slug}']->completionProviders['category'];
        $this->assertInstanceOf(ListCompletionProvider::class, $categoryProvider);
    }
}
