<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Registry\Source;

use Mcp\Schema\Tool;
use Mcp\Server\Capability\Discovery\DiscovererInterface;
use Mcp\Server\Capability\Discovery\DiscoveryState;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\Loader\ChainLoader;
use Mcp\Server\Capability\Registry\Loader\SourceLoader;
use Mcp\Server\Capability\Registry\Source\DiscoverySource;
use Mcp\Server\Capability\Registry\Source\ElementCollection;
use Mcp\Server\Capability\Registry\Source\ElementSourceInterface;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Server\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

final class ElementSourceTest extends TestCase
{
    public function testSourceLoaderWritesEveryKindAndOverwrites(): void
    {
        $registry = new Registry();
        $registry->registerTool($this->tool('alpha'), 'existing');

        (new SourceLoader($this->source(new ElementCollection(tools: [new ToolReference($this->tool('alpha'), 'provided')]))))->load($registry);

        $this->assertSame('provided', $registry->getTool('alpha')->handler);
    }

    public function testSourceLoaderOnlyFillsGapsWhenAskedTo(): void
    {
        $registry = new Registry();
        $registry->registerTool($this->tool('alpha'), 'existing');

        (new SourceLoader($this->source(new ElementCollection(tools: [
            new ToolReference($this->tool('alpha'), 'provided'),
            new ToolReference($this->tool('beta'), 'provided'),
        ])), onlyMissing: true))->load($registry);

        $this->assertSame('existing', $registry->getTool('alpha')->handler);
        $this->assertSame('provided', $registry->getTool('beta')->handler);
    }

    public function testAFailedSourceIsRetriedOnTheNextReadWhileOthersStayServable(): void
    {
        $healthy = new SourceLoader($this->source(new ElementCollection(tools: [new ToolReference($this->tool('alpha'), 'handler')])));
        $source = new class($this->tool('beta')) implements ElementSourceInterface {
            public bool $available = false;

            public function __construct(private Tool $tool)
            {
            }

            public function provide(): ElementCollection
            {
                if (!$this->available) {
                    throw new RuntimeException('database down');
                }

                return new ElementCollection(tools: [new ToolReference($this->tool, 'handler')]);
            }
        };

        $registry = new Registry(loader: new ChainLoader([$healthy, new SourceLoader($source)]));

        $this->assertTrue($registry->hasTool('alpha'));
        $this->assertFalse($registry->hasTool('beta'));
        $this->assertFalse($registry->isLoaded());

        $source->available = true;

        $this->assertTrue($registry->hasTool('beta'));
        $this->assertTrue($registry->isLoaded());
    }

    public function testCollectionOfNamedHandlersSurvivesSerialization(): void
    {
        $collection = new ElementCollection(tools: [new ToolReference($this->tool('alpha'), 'handler')]);

        $this->assertEquals($collection, unserialize(serialize($collection)));
    }

    public function testDiscoverySourceProvidesWhatTheDiscovererFound(): void
    {
        $reference = new ToolReference($this->tool('alpha'), 'handler');
        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->once())
            ->method('discover')
            ->with('/base', ['src'], ['vendor'], ['*.php'])
            ->willReturn(new DiscoveryState(tools: ['alpha' => $reference]));

        $source = new DiscoverySource('/base', ['src'], ['vendor'], $discoverer, ['*.php']);

        $this->assertSame(['alpha' => $reference], $source->provide()->getTools());
    }

    private function tool(string $name): Tool
    {
        return new Tool(name: $name, title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: null, annotations: null);
    }

    private function source(ElementCollection $collection): ElementSourceInterface
    {
        return new class($collection) implements ElementSourceInterface {
            public function __construct(private ElementCollection $collection)
            {
            }

            public function provide(): ElementCollection
            {
                return $this->collection;
            }
        };
    }
}
