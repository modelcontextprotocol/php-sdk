<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Registry;

use Mcp\Schema\Tool;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\LazyRegistry;
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\RuntimeException;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use PHPUnit\Framework\TestCase;

final class LazyRegistryTest extends TestCase
{
    public function testLoadsOnceOnFirstRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $registry = new LazyRegistry(new Registry(), $loader);

        $registry->getTools();
        $registry->hasTools();
    }

    public function testWritesAndListChangeSupportDoNotLoad(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->never())->method('load');

        $registry = new LazyRegistry(new Registry(), $loader);

        $registry->registerTool($this->tool('alpha'), static fn (): string => 'ok');
        $registry->unregisterTool('alpha');
        $registry->publishesListChanges();
    }

    public function testAReadDuringARunningLoadLeavesItPending(): void
    {
        $loader = new class implements LoaderInterface {
            public int $calls = 0;

            public function load(RegistryInterface $registry): void
            {
                if (1 === ++$this->calls) {
                    \Fiber::suspend();

                    throw new RuntimeException('source not ready');
                }
            }
        };

        $registry = new LazyRegistry(new Registry(), $loader);
        $fiber = new \Fiber($registry->load(...));
        $fiber->start();

        $registry->load();
        $this->assertFalse($registry->isLoaded());

        try {
            $fiber->resume();
            $this->fail('The failure must reach the reader that started the load.');
        } catch (RuntimeException) {
        }
        $this->assertFalse($registry->isLoaded());

        $registry->load();
        $this->assertSame(2, $loader->calls);
        $this->assertTrue($registry->isLoaded());
    }

    public function testFailedLoadIsRetriedOnTheNextRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->exactly(2))
            ->method('load')
            ->willReturnCallback(static function () {
                static $calls = 0;
                if (1 === ++$calls) {
                    throw new RuntimeException('source not ready');
                }
            });

        $registry = new LazyRegistry(new Registry(), $loader);

        try {
            $registry->getTools();
            $this->fail('The load failure must reach the reader.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $registry->getTools()->references);
    }

    public function testStaysPendingUntilTheWrappedRegistryHasLoaded(): void
    {
        $own = new class implements LoaderInterface {
            public int $calls = 0;

            public function load(RegistryInterface $registry): void
            {
                if (1 === ++$this->calls) {
                    throw new RuntimeException('source not ready');
                }
            }
        };
        $builder = $this->createMock(LoaderInterface::class);
        $builder->expects($this->once())->method('load');

        $registry = new LazyRegistry(new Registry(loader: $own), $builder);

        try {
            $registry->load();
            $this->fail('The failure of the wrapped registry must reach the caller.');
        } catch (RuntimeException) {
        }
        $this->assertFalse($registry->isLoaded());

        $registry->load();
        $this->assertTrue($registry->isLoaded());
        $this->assertSame(2, $own->calls);
    }

    public function testRunsItsLoaderWhileTheWrappedRegistryLoadsInAnotherFiber(): void
    {
        $suspending = new class implements LoaderInterface {
            public function load(RegistryInterface $registry): void
            {
                \Fiber::suspend();
            }
        };
        $inner = new Registry(loader: $suspending);
        $fiber = new \Fiber($inner->load(...));
        $fiber->start();

        $registry = new LazyRegistry($inner, $this->writing($this->tool('alpha')));
        $registry->load();
        $this->assertFalse($registry->isLoaded());

        $fiber->resume();

        $this->assertTrue($registry->isLoaded());
        $this->assertTrue($registry->hasTool('alpha'));
    }

    public function testExposesWhatTheLoaderWroteToTheWrappedRegistry(): void
    {
        $inner = new Registry();
        $registry = new LazyRegistry($inner, $this->writing($this->tool('alpha')));

        $this->assertFalse($inner->hasTool('alpha'));
        $this->assertSame('alpha', $registry->getTool('alpha')->tool->name);
        $this->assertTrue($inner->hasTool('alpha'));
    }

    public function testLoadIsNotAnnouncedAsAChangeByADeferredRegistry(): void
    {
        $bus = new InMemoryNotificationBus();
        $registry = new LazyRegistry(new Registry(new PublishingEventDispatcher($bus)), $this->writing($this->tool('alpha')));

        $registry->load();

        $this->assertTrue($registry->hasTool('alpha'));
        $this->assertSame(0, $bus->cursor());
    }

    public function testLoadsAThirdPartyRegistryThroughThePlainLoader(): void
    {
        $inner = $this->createMock(RegistryInterface::class);
        $inner->expects($this->once())->method('registerTool');
        $inner->method('hasTools')->willReturn(true);

        $registry = new LazyRegistry($inner, $this->writing($this->tool('alpha')));

        $this->assertTrue($registry->hasTools());
    }

    private function tool(string $name): Tool
    {
        return new Tool(name: $name, title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: null, annotations: null);
    }

    private function writing(Tool $tool): LoaderInterface
    {
        return new class($tool) implements LoaderInterface {
            public function __construct(private Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, static fn (): string => 'ok');
            }
        };
    }
}
