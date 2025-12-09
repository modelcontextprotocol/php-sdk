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
use Mcp\Schema\Tool;
use Mcp\Tests\Unit\Capability\Provider\Fixtures\TestDynamicToolProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DynamicToolProviderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry(null, new NullLogger());
    }

    public function testProviderRegistrationInRegistry(): void
    {
        $tool = $this->createTool('test_tool');
        $provider = new TestDynamicToolProvider([$tool]);

        $this->registry->registerDynamicToolProvider($provider);

        $providers = $this->registry->getDynamicToolProviders();
        $this->assertCount(1, $providers);
        $this->assertSame($provider, $providers[0]);
    }

    public function testToolEnumerationFromDynamicProvider(): void
    {
        $tool1 = $this->createTool('dynamic_tool_1');
        $tool2 = $this->createTool('dynamic_tool_2');
        $provider = new TestDynamicToolProvider([$tool1, $tool2]);

        $this->registry->registerDynamicToolProvider($provider);

        $page = $this->registry->getTools();
        $tools = $page->references;

        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('dynamic_tool_1', $tools);
        $this->assertArrayHasKey('dynamic_tool_2', $tools);
        $this->assertSame($tool1, $tools['dynamic_tool_1']);
        $this->assertSame($tool2, $tools['dynamic_tool_2']);
    }

    public function testToolEnumerationFromMixedSources(): void
    {
        // Register a static tool
        $staticTool = $this->createTool('static_tool');
        $this->registry->registerTool($staticTool, fn () => 'static result');

        // Register a dynamic provider
        $dynamicTool = $this->createTool('dynamic_tool');
        $provider = new TestDynamicToolProvider([$dynamicTool]);
        $this->registry->registerDynamicToolProvider($provider);

        $page = $this->registry->getTools();
        $tools = $page->references;

        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('static_tool', $tools);
        $this->assertArrayHasKey('dynamic_tool', $tools);
    }

    public function testConflictDetectionStaticVsDynamic(): void
    {
        // Register a static tool first
        $staticTool = $this->createTool('conflicting_tool');
        $this->registry->registerTool($staticTool, fn () => 'static result');

        // Try to register a dynamic provider with the same tool name
        $dynamicTool = $this->createTool('conflicting_tool');
        $provider = new TestDynamicToolProvider([$dynamicTool]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic tool provider conflict: tool "conflicting_tool" is already registered as a static tool.');

        $this->registry->registerDynamicToolProvider($provider);
    }

    public function testConflictDetectionDynamicVsDynamic(): void
    {
        // Register first dynamic provider
        $tool1 = $this->createTool('shared_tool');
        $provider1 = new TestDynamicToolProvider([$tool1]);
        $this->registry->registerDynamicToolProvider($provider1);

        // Try to register second dynamic provider with the same tool name
        $tool2 = $this->createTool('shared_tool');
        $provider2 = new TestDynamicToolProvider([$tool2]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic tool provider conflict: tool "shared_tool" is already supported by another provider.');

        $this->registry->registerDynamicToolProvider($provider2);
    }

    private function createTool(string $name): Tool
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
}
