<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry\Loader;

use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\DiscoveryLoader;
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
use Mcp\Tests\Unit\Capability\Registry\Loader\Stub\MutableDiscoverer;
use PHPUnit\Framework\TestCase;

class DiscoveryLoaderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry();
    }

    public function testLoadRegistersAllDiscoveredElements(): void
    {
        $loader = new DiscoveryLoader('/base', [], [], new MutableDiscoverer(new DiscoveryState(
            tools: ['t1' => new ToolReference($this->makeTool('t1'), static fn () => 't1')],
            resources: ['r://1' => new ResourceReference($this->makeResource('r://1'), static fn () => 'r1')],
            prompts: ['p1' => new PromptReference($this->makePrompt('p1'), static fn () => [])],
            resourceTemplates: ['t://{id}' => new ResourceTemplateReference($this->makeTemplate('t://{id}'), static fn () => 'tpl')],
        )));

        $loader->load($this->registry);

        $this->assertInstanceOf(ToolReference::class, $this->registry->getTool('t1'));
        $this->assertInstanceOf(ResourceReference::class, $this->registry->getResource('r://1', false));
        $this->assertInstanceOf(PromptReference::class, $this->registry->getPrompt('p1'));
        $this->assertInstanceOf(ResourceTemplateReference::class, $this->registry->getResourceTemplate('t://{id}'));
    }

    public function testLoadTwiceUnregistersStaleAndKeepsNew(): void
    {
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['t1' => new ToolReference($this->makeTool('t1'), static fn () => 't1')],
            resources: ['r://1' => new ResourceReference($this->makeResource('r://1'), static fn () => 'r1')],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);

        $loader->load($this->registry);

        // Second discovery: t1 is gone, t2 appears; resource r://1 still present, new r://2 added.
        $discoverer->state = new DiscoveryState(
            tools: ['t2' => new ToolReference($this->makeTool('t2'), static fn () => 't2')],
            resources: [
                'r://1' => new ResourceReference($this->makeResource('r://1'), static fn () => 'r1-updated'),
                'r://2' => new ResourceReference($this->makeResource('r://2'), static fn () => 'r2'),
            ],
        );
        $loader->load($this->registry);

        $this->assertInstanceOf(ToolReference::class, $this->registry->getTool('t2'));
        $this->assertInstanceOf(ResourceReference::class, $this->registry->getResource('r://2', false));

        $updatedResource = $this->registry->getResource('r://1', false);
        $this->assertInstanceOf(ResourceReference::class, $updatedResource);
        $this->assertSame('r1-updated', ($updatedResource->handler)());

        $this->expectException(ToolNotFoundException::class);
        $this->registry->getTool('t1');
    }

    public function testLoadTwiceUnregistersStalePromptsAndTemplates(): void
    {
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            prompts: [
                'p1' => new PromptReference($this->makePrompt('p1'), static fn () => []),
                'p2' => new PromptReference($this->makePrompt('p2'), static fn () => []),
            ],
            resourceTemplates: [
                't1://{id}' => new ResourceTemplateReference($this->makeTemplate('t1://{id}'), static fn () => 'tpl1'),
                't2://{id}' => new ResourceTemplateReference($this->makeTemplate('t2://{id}'), static fn () => 'tpl2'),
            ],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);
        $loader->load($this->registry);

        // Second discovery: drop p1 and t1, keep p2 and t2, add p3 and t3.
        $discoverer->state = new DiscoveryState(
            prompts: [
                'p2' => new PromptReference($this->makePrompt('p2'), static fn () => []),
                'p3' => new PromptReference($this->makePrompt('p3'), static fn () => []),
            ],
            resourceTemplates: [
                't2://{id}' => new ResourceTemplateReference($this->makeTemplate('t2://{id}'), static fn () => 'tpl2'),
                't3://{id}' => new ResourceTemplateReference($this->makeTemplate('t3://{id}'), static fn () => 'tpl3'),
            ],
        );
        $loader->load($this->registry);

        $this->assertInstanceOf(PromptReference::class, $this->registry->getPrompt('p2'));
        $this->assertInstanceOf(PromptReference::class, $this->registry->getPrompt('p3'));
        $this->assertInstanceOf(ResourceTemplateReference::class, $this->registry->getResourceTemplate('t2://{id}'));
        $this->assertInstanceOf(ResourceTemplateReference::class, $this->registry->getResourceTemplate('t3://{id}'));

        $missing = 0;
        try {
            $this->registry->getPrompt('p1');
        } catch (PromptNotFoundException) {
            ++$missing;
        }
        try {
            $this->registry->getResourceTemplate('t1://{id}');
        } catch (ResourceNotFoundException) {
            ++$missing;
        }
        $this->assertSame(2, $missing);
    }

    public function testLoadOverwritesPreviousRegistrationOnSameKey(): void
    {
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['t' => new ToolReference($this->makeTool('t'), static fn () => 'v1')],
            prompts: ['p' => new PromptReference($this->makePrompt('p'), static fn () => [], ['arg' => EnumCompletionProvider::class])],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);
        $loader->load($this->registry);

        // Same names, different handlers / completion providers.
        $discoverer->state = new DiscoveryState(
            tools: ['t' => new ToolReference($this->makeTool('t'), static fn () => 'v2')],
            prompts: ['p' => new PromptReference($this->makePrompt('p'), static fn () => [], ['arg' => ListCompletionProvider::class])],
        );
        $loader->load($this->registry);

        $this->assertSame('v2', ($this->registry->getTool('t')->handler)());
        $this->assertSame(['arg' => ListCompletionProvider::class], $this->registry->getPrompt('p')->completionProviders);
    }

    public function testLoadPreservesConflictingRuntimeRegistration(): void
    {
        // Application registers a tool directly.
        $this->registry->registerTool($this->makeTool('shared'), static fn () => 'runtime');

        // Discovery later finds the same name. The manual registration wins —
        // discovery does not clobber entries it doesn't own.
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['shared' => new ToolReference($this->makeTool('shared'), static fn () => 'discovered')],
        ));
        (new DiscoveryLoader('/base', [], [], $discoverer))->load($this->registry);

        $this->assertSame('runtime', ($this->registry->getTool('shared')->handler)());
    }

    public function testLoadPreservesRuntimeOverrideOfPreviouslyOwnedEntry(): void
    {
        // First discovery owns 'shared'.
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['shared' => new ToolReference($this->makeTool('shared'), static fn () => 'discovered-v1')],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);
        $loader->load($this->registry);

        // Developer overrides at runtime.
        $this->registry->registerTool($this->makeTool('shared'), static fn () => 'runtime');

        // Rediscovery still finds 'shared'; loader sees the registry no longer holds its instance and steps aside.
        $discoverer->state = new DiscoveryState(
            tools: ['shared' => new ToolReference($this->makeTool('shared'), static fn () => 'discovered-v2')],
        );
        $loader->load($this->registry);

        $this->assertSame('runtime', ($this->registry->getTool('shared')->handler)());
    }

    public function testLoadDoesNotUnregisterRuntimeAdditions(): void
    {
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['discovered_tool' => new ToolReference($this->makeTool('discovered_tool'), static fn () => 'discovered')],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);

        $loader->load($this->registry);

        // Application registers a tool directly between two discovery runs.
        $this->registry->registerTool($this->makeTool('runtime_tool'), static fn () => 'runtime');

        // Second discovery run with a different state. The runtime tool must survive.
        $discoverer->state = new DiscoveryState(
            tools: ['discovered_tool_v2' => new ToolReference($this->makeTool('discovered_tool_v2'), static fn () => 'v2')],
        );
        $loader->load($this->registry);

        $this->assertInstanceOf(ToolReference::class, $this->registry->getTool('runtime_tool'));
        $this->assertInstanceOf(ToolReference::class, $this->registry->getTool('discovered_tool_v2'));

        $this->expectException(ToolNotFoundException::class);
        $this->registry->getTool('discovered_tool');
    }

    public function testEmptySecondLoadUnregistersAllPreviouslyDiscovered(): void
    {
        $discoverer = new MutableDiscoverer(new DiscoveryState(
            tools: ['t' => new ToolReference($this->makeTool('t'), static fn () => 't')],
            resources: ['r://x' => new ResourceReference($this->makeResource('r://x'), static fn () => 'rx')],
            prompts: ['p' => new PromptReference($this->makePrompt('p'), static fn () => [])],
            resourceTemplates: ['x://{id}' => new ResourceTemplateReference($this->makeTemplate('x://{id}'), static fn () => 'tpl')],
        ));
        $loader = new DiscoveryLoader('/base', [], [], $discoverer);
        $loader->load($this->registry);

        $discoverer->state = new DiscoveryState();
        $loader->load($this->registry);

        $exceptions = 0;
        try {
            $this->registry->getTool('t');
        } catch (ToolNotFoundException) {
            ++$exceptions;
        }
        try {
            $this->registry->getResource('r://x', false);
        } catch (ResourceNotFoundException) {
            ++$exceptions;
        }
        try {
            $this->registry->getPrompt('p');
        } catch (PromptNotFoundException) {
            ++$exceptions;
        }
        try {
            $this->registry->getResourceTemplate('x://{id}');
        } catch (ResourceNotFoundException) {
            ++$exceptions;
        }
        $this->assertSame(4, $exceptions);
    }

    private function makeTool(string $name): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: null,
            annotations: null,
            icons: null,
            meta: null,
            outputSchema: null,
        );
    }

    private function makeResource(string $uri): Resource
    {
        return new Resource(uri: $uri, name: 'r', description: null, mimeType: 'text/plain');
    }

    private function makePrompt(string $name): Prompt
    {
        return new Prompt(name: $name, description: null, arguments: []);
    }

    private function makeTemplate(string $uriTemplate): ResourceTemplate
    {
        return new ResourceTemplate(uriTemplate: $uriTemplate, name: 'tpl', description: null, mimeType: 'text/plain');
    }
}
