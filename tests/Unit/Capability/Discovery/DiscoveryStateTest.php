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

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

class DiscoveryStateTest extends TestCase
{
    public function testObsoletedByReturnsEntriesAbsentFromNext(): void
    {
        $t1 = $this->tool('t1');
        $t2 = $this->tool('t2');

        $owned = new DiscoveryState(
            tools: ['t1' => $t1, 't2' => $t2],
        );
        $next = new DiscoveryState(
            tools: ['t2' => $this->tool('t2'), 't3' => $this->tool('t3')],
        );

        $obsolete = $owned->obsoletedBy($next);

        $this->assertSame(['t1' => $t1], $obsolete->getTools());
    }

    public function testObsoletedByIsAsymmetricAndIgnoresValuesOnSharedKeys(): void
    {
        // Same key, different reference instance — must NOT be reported as obsolete.
        $owned = new DiscoveryState(
            tools: ['t' => $this->tool('t')],
        );
        $next = new DiscoveryState(
            tools: ['t' => $this->tool('t')],
        );

        $this->assertTrue($owned->obsoletedBy($next)->isEmpty());
    }

    public function testObsoletedByOnEmptyNextReturnsAllOwned(): void
    {
        $owned = new DiscoveryState(
            tools: ['t' => $this->tool('t')],
            resources: ['r://x' => $this->resource('r://x')],
            prompts: ['p' => $this->prompt('p')],
            resourceTemplates: ['x://{id}' => $this->template('x://{id}')],
        );

        $obsolete = $owned->obsoletedBy(new DiscoveryState());

        $this->assertSame(['t'], array_keys($obsolete->getTools()));
        $this->assertSame(['r://x'], array_keys($obsolete->getResources()));
        $this->assertSame(['p'], array_keys($obsolete->getPrompts()));
        $this->assertSame(['x://{id}'], array_keys($obsolete->getResourceTemplates()));
    }

    public function testObsoletedByOnEmptyOwnedReturnsEmpty(): void
    {
        $next = new DiscoveryState(
            tools: ['t' => $this->tool('t')],
        );

        $this->assertTrue((new DiscoveryState())->obsoletedBy($next)->isEmpty());
    }

    public function testObsoletedByKeepsKindsIndependent(): void
    {
        // A key shared across different kinds must not cancel out.
        $owned = new DiscoveryState(
            tools: ['shared' => $this->tool('shared')],
            prompts: ['shared' => $this->prompt('shared')],
        );
        $next = new DiscoveryState(
            tools: ['shared' => $this->tool('shared')],
            // 'shared' prompt is gone.
        );

        $obsolete = $owned->obsoletedBy($next);

        $this->assertSame([], $obsolete->getTools());
        $this->assertSame(['shared'], array_keys($obsolete->getPrompts()));
    }

    public function testObsoletedByAcrossAllKindsAtOnce(): void
    {
        $owned = new DiscoveryState(
            tools: ['keep_t' => $this->tool('keep_t'), 'drop_t' => $this->tool('drop_t')],
            resources: ['r://keep' => $this->resource('r://keep'), 'r://drop' => $this->resource('r://drop')],
            prompts: ['keep_p' => $this->prompt('keep_p'), 'drop_p' => $this->prompt('drop_p')],
            resourceTemplates: ['keep://{id}' => $this->template('keep://{id}'), 'drop://{id}' => $this->template('drop://{id}')],
        );
        $next = new DiscoveryState(
            tools: ['keep_t' => $this->tool('keep_t')],
            resources: ['r://keep' => $this->resource('r://keep')],
            prompts: ['keep_p' => $this->prompt('keep_p')],
            resourceTemplates: ['keep://{id}' => $this->template('keep://{id}')],
        );

        $obsolete = $owned->obsoletedBy($next);

        $this->assertSame(['drop_t'], array_keys($obsolete->getTools()));
        $this->assertSame(['r://drop'], array_keys($obsolete->getResources()));
        $this->assertSame(['drop_p'], array_keys($obsolete->getPrompts()));
        $this->assertSame(['drop://{id}'], array_keys($obsolete->getResourceTemplates()));
    }

    private function tool(string $name): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: $name,
                title: null,
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
                description: null,
                annotations: null,
                icons: null,
                meta: null,
                outputSchema: null,
            ),
            static fn () => null,
        );
    }

    private function resource(string $uri): ResourceReference
    {
        return new ResourceReference(
            new ResourceDefinition(uri: $uri, name: 'r', description: null, mimeType: 'text/plain'),
            static fn () => null,
        );
    }

    private function prompt(string $name): PromptReference
    {
        return new PromptReference(
            new Prompt(name: $name, description: null, arguments: []),
            static fn () => [],
        );
    }

    private function template(string $uriTemplate): ResourceTemplateReference
    {
        return new ResourceTemplateReference(
            new ResourceTemplate(uriTemplate: $uriTemplate, name: 'tpl', description: null, mimeType: 'text/plain'),
            static fn () => null,
        );
    }
}
