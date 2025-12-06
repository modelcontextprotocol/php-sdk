<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Provider;

use Mcp\Capability\Registry;
use Mcp\Exception\RegistryException;
use Mcp\Schema\Prompt;
use Mcp\Tests\Unit\Capability\Provider\Fixtures\TestDynamicPromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DynamicPromptProviderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry(null, new NullLogger());
    }

    public function testProviderRegistrationInRegistry(): void
    {
        $prompt = $this->createPrompt('test_prompt');
        $provider = new TestDynamicPromptProvider([$prompt]);

        $this->registry->registerDynamicPromptProvider($provider);

        $providers = $this->registry->getDynamicPromptProviders();
        $this->assertCount(1, $providers);
        $this->assertSame($provider, $providers[0]);
    }

    public function testPromptEnumerationFromDynamicProvider(): void
    {
        $prompt1 = $this->createPrompt('dynamic_prompt_1');
        $prompt2 = $this->createPrompt('dynamic_prompt_2');
        $provider = new TestDynamicPromptProvider([$prompt1, $prompt2]);

        $this->registry->registerDynamicPromptProvider($provider);

        $page = $this->registry->getPrompts();
        $prompts = $page->references;

        $this->assertCount(2, $prompts);
        $this->assertArrayHasKey('dynamic_prompt_1', $prompts);
        $this->assertArrayHasKey('dynamic_prompt_2', $prompts);
        $this->assertSame($prompt1, $prompts['dynamic_prompt_1']);
        $this->assertSame($prompt2, $prompts['dynamic_prompt_2']);
    }

    public function testPromptEnumerationFromMixedSources(): void
    {
        // Register a static prompt
        $staticPrompt = $this->createPrompt('static_prompt');
        $this->registry->registerPrompt($staticPrompt, fn () => []);

        // Register a dynamic provider
        $dynamicPrompt = $this->createPrompt('dynamic_prompt');
        $provider = new TestDynamicPromptProvider([$dynamicPrompt]);
        $this->registry->registerDynamicPromptProvider($provider);

        $page = $this->registry->getPrompts();
        $prompts = $page->references;

        $this->assertCount(2, $prompts);
        $this->assertArrayHasKey('static_prompt', $prompts);
        $this->assertArrayHasKey('dynamic_prompt', $prompts);
    }

    public function testConflictDetectionStaticVsDynamic(): void
    {
        // Register a static prompt first
        $staticPrompt = $this->createPrompt('conflicting_prompt');
        $this->registry->registerPrompt($staticPrompt, fn () => []);

        // Try to register a dynamic provider with the same prompt name
        $dynamicPrompt = $this->createPrompt('conflicting_prompt');
        $provider = new TestDynamicPromptProvider([$dynamicPrompt]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic prompt provider conflict: prompt "conflicting_prompt" is already registered as a static prompt.');

        $this->registry->registerDynamicPromptProvider($provider);
    }

    public function testConflictDetectionDynamicVsDynamic(): void
    {
        // Register first dynamic provider
        $prompt1 = $this->createPrompt('shared_prompt');
        $provider1 = new TestDynamicPromptProvider([$prompt1]);
        $this->registry->registerDynamicPromptProvider($provider1);

        // Try to register second dynamic provider with the same prompt name
        $prompt2 = $this->createPrompt('shared_prompt');
        $provider2 = new TestDynamicPromptProvider([$prompt2]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic prompt provider conflict: prompt "shared_prompt" is already supported by another provider.');

        $this->registry->registerDynamicPromptProvider($provider2);
    }

    private function createPrompt(string $name): Prompt
    {
        return new Prompt(
            name: $name,
            description: "Test prompt: {$name}",
            arguments: [],
        );
    }
}
