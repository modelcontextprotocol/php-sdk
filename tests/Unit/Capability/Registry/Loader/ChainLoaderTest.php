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

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\ChainLoader;
use Mcp\Tests\Unit\Capability\Registry\Loader\Stub\RecordingLoader;
use Mcp\Tests\Unit\Capability\Registry\Loader\Stub\ToolWriterLoader;
use PHPUnit\Framework\TestCase;

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

        $this->assertSame('second', ($registry->getTool('shared')->handler)());
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
}
