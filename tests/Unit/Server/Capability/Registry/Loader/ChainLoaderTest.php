<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Registry\Loader;

use Mcp\Schema\Tool;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\Loader\ChainLoader;
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\Registry\Loader\SourceLoader;
use Mcp\Server\Capability\Registry\Source\ElementCollection;
use Mcp\Server\Capability\Registry\Source\ElementSourceInterface;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\ConfigurationException;
use Mcp\Server\Exception\PartialLoadException;
use Mcp\Server\Exception\RuntimeException;
use Mcp\Tests\Unit\Server\Capability\Registry\Loader\Stub\RecordingLoader;
use Mcp\Tests\Unit\Server\Capability\Registry\Loader\Stub\ToolWriterLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChainLoaderTest extends TestCase
{
    public function testInvokesChildLoadersInOrder(): void
    {
        $calls = new \ArrayObject();
        $a = new RecordingLoader('A', $calls);
        $b = new RecordingLoader('B', $calls);
        $c = new RecordingLoader('C', $calls);

        (new ChainLoader([$a, $b, $c]))->load(new Registry());

        $this->assertSame(['A', 'B', 'C'], $calls->getArrayCopy());
    }

    public function testLastWriterWinsForConflictingKeys(): void
    {
        $registry = new Registry();

        $first = new ToolWriterLoader('shared', static fn () => 'first');
        $second = new ToolWriterLoader('shared', static fn () => 'second');

        (new ChainLoader([$first, $second]))->load($registry);

        $handler = $registry->getTool('shared')->handler;
        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame('second', $handler());
    }

    public function testEmptyChainIsNoop(): void
    {
        $registry = new Registry();

        (new ChainLoader([]))->load($registry);

        $this->assertFalse($registry->hasTools());
        $this->assertFalse($registry->hasResources());
        $this->assertFalse($registry->hasResourceTemplates());
        $this->assertFalse($registry->hasPrompts());
    }

    public function testAFailingLoaderDoesNotStopTheOthersAndIsReported(): void
    {
        $calls = new \ArrayObject();
        $failing = $this->createStub(LoaderInterface::class);
        $failing->method('load')->willThrowException(new RuntimeException('source down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $chain = new ChainLoader([$failing, new RecordingLoader('after', $calls)], $logger);

        try {
            $chain->load(new Registry());
            $this->fail('The chain must report that it is incomplete.');
        } catch (PartialLoadException) {
        }

        $this->assertSame(['after'], $calls->getArrayCopy());
    }

    public function testAConfigurationErrorEndsTheLoad(): void
    {
        $calls = new \ArrayObject();
        $misconfigured = $this->createStub(LoaderInterface::class);
        $misconfigured->method('load')->willThrowException(new ConfigurationException('handler class not found'));

        $registry = new Registry();
        $chain = new ChainLoader([new RecordingLoader('before', $calls), $misconfigured, new RecordingLoader('after', $calls)]);

        foreach ([1, 2] as $attempt) {
            try {
                $chain->load($registry);
                $this->fail('A configuration error must reach the caller.');
            } catch (ConfigurationException) {
            }
        }

        $this->assertSame(['before', 'before'], $calls->getArrayCopy());
    }

    public function testARetryRunsOnlyTheFailedSourceWhileItStaysDown(): void
    {
        $calls = new \ArrayObject();
        $source = $this->createStub(ElementSourceInterface::class);
        $source->method('provide')->willThrowException(new RuntimeException('source down'));

        $registry = new Registry();
        $chain = new ChainLoader([new RecordingLoader('before', $calls), new SourceLoader($source), new RecordingLoader('after', $calls)]);

        for ($i = 0; $i < 3; ++$i) {
            try {
                $chain->load($registry);
                $this->fail('The chain must report that it is incomplete.');
            } catch (PartialLoadException) {
            }
        }

        $this->assertSame(['before', 'after'], $calls->getArrayCopy());
    }

    public function testARecoveredLoaderDoesNotOverwriteLaterLoaders(): void
    {
        $recovering = new class implements LoaderInterface {
            public bool $down = true;

            public function load(RegistryInterface $registry): void
            {
                if ($this->down) {
                    throw new RuntimeException('source down');
                }

                (new ToolWriterLoader('shared', static fn () => 'recovered'))->load($registry);
            }
        };

        $registry = new Registry();
        $chain = new ChainLoader([$recovering, new ToolWriterLoader('shared', static fn () => 'later')]);

        try {
            $chain->load($registry);
            $this->fail('The chain must report that it is incomplete.');
        } catch (PartialLoadException) {
        }

        $recovering->down = false;
        $chain->load($registry);

        $handler = $registry->getTool('shared')->handler;
        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame('later', $handler());
    }

    public function testAFailingLoaderThatWroteDoesNotOverwriteLaterLoadersOnRetry(): void
    {
        $failing = new class implements LoaderInterface {
            public function load(RegistryInterface $registry): void
            {
                (new ToolWriterLoader('shared', static fn () => 'failing'))->load($registry);

                throw new RuntimeException('source down');
            }
        };

        $registry = new Registry();
        $chain = new ChainLoader([$failing, new ToolWriterLoader('shared', static fn () => 'later')]);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $chain->load($registry);
                $this->fail('The chain must report that it is incomplete.');
            } catch (PartialLoadException) {
            }
        }

        $handler = $registry->getTool('shared')->handler;
        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame('later', $handler());
    }

    public function testASourceFailingDuringRegistrationIsFollowedByLaterLoadersOnRetry(): void
    {
        $calls = new \ArrayObject();
        $source = $this->createStub(ElementSourceInterface::class);
        $source->method('provide')->willReturn(new ElementCollection(tools: [
            new ToolReference(new Tool(name: 'shared', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: null, annotations: null), 'handler'),
        ]));

        $registry = $this->createStub(RegistryInterface::class);
        $registry->method('registerTool')->willThrowException(new RuntimeException('registry down'));

        $chain = new ChainLoader([new SourceLoader($source), new RecordingLoader('after', $calls)]);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $chain->load($registry);
                $this->fail('The chain must report that it is incomplete.');
            } catch (PartialLoadException) {
            }
        }

        $this->assertSame(['after', 'after'], $calls->getArrayCopy());
    }

    public function testACompleteChainRunsAllLoadersOnTheNextLoad(): void
    {
        $calls = new \ArrayObject();
        $registry = new Registry();
        $chain = new ChainLoader([new RecordingLoader('A', $calls), new RecordingLoader('B', $calls)]);

        $chain->load($registry);
        $chain->load($registry);

        $this->assertSame(['A', 'B', 'A', 'B'], $calls->getArrayCopy());
    }
}
