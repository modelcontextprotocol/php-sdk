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

use Mcp\Capability\LazyRegistry;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

class LazyRegistryTest extends TestCase
{
    public function testLoaderIsNotRunUntilFirstRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->never())->method('load');

        // Constructing (and registering) must not trigger the loader.
        $registry = new LazyRegistry(new Registry(), $loader);
        $registry->registerTool($this->tool('manual'), 'handler');
    }

    public function testLoaderRunsOnFirstReadAndPopulatesTheRegistry(): void
    {
        $inner = new Registry();
        $loader = new class($this->tool('loaded')) implements LoaderInterface {
            public function __construct(private readonly Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, 'handler');
            }
        };

        $registry = new LazyRegistry($inner, $loader);

        $this->assertTrue($registry->hasTools());
        $tools = $registry->getTools()->references;
        $this->assertArrayHasKey('loaded', $tools);
    }

    public function testLoaderRunsExactlyOnceAcrossManyReads(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $registry = new LazyRegistry(new Registry(), $loader);
        $registry->hasTools();
        $registry->getTools();
        $registry->hasResources();
        $registry->getPrompts();
    }

    public function testRuntimeRegistrationsSurviveTheDeferredLoad(): void
    {
        $inner = new Registry();
        $loader = new class($this->tool('loaded')) implements LoaderInterface {
            public function __construct(private readonly Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, 'handler');
            }
        };

        $registry = new LazyRegistry($inner, $loader);
        // Registered before the first read; the deferred load must be additive, not replacing.
        $registry->registerTool($this->tool('runtime'), 'handler');

        $tools = $registry->getTools()->references;
        $this->assertArrayHasKey('runtime', $tools);
        $this->assertArrayHasKey('loaded', $tools);
    }

    public function testLoaderRetriesAfterAFailedLoad(): void
    {
        $inner = new Registry();
        $loader = new class($this->tool('loaded')) implements LoaderInterface {
            private int $calls = 0;

            public function __construct(private readonly Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                ++$this->calls;
                if (1 === $this->calls) {
                    throw new \RuntimeException('data source not ready');
                }

                $registry->registerTool($this->tool, 'handler');
            }
        };

        $registry = new LazyRegistry($inner, $loader);

        try {
            $registry->hasTools();
            $this->fail('Expected the first load to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('data source not ready', $e->getMessage());
        }

        $tools = $registry->getTools()->references;
        $this->assertArrayHasKey('loaded', $tools);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object', 'properties' => [], 'required' => null], null, null);
    }
}
