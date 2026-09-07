<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Completion;

use Mcp\Capability\Completion\ListCompletionProvider;
use PHPUnit\Framework\TestCase;

final class ListCompletionProviderTest extends TestCase
{
    public function testCompletionsAreOfferedInFull(): void
    {
        $provider = new ListCompletionProvider(['draft', 'published', 'archived']);

        $this->assertSame(['draft', 'published', 'archived'], $provider->getCompletions(''));
    }

    public function testCompletionsArePrefixMatched(): void
    {
        $provider = new ListCompletionProvider(['draft', 'published', 'archived']);

        $this->assertSame(['draft'], $provider->getCompletions('dr'));
    }

    /**
     * `#[CompletionProvider(values: ...)]` accepts ints and floats, but a
     * completion goes on the wire as a string.
     */
    public function testNumericValuesBecomeStrings(): void
    {
        $provider = new ListCompletionProvider([101, 102.5, 'abc']);

        $this->assertSame(['101', '102.5', 'abc'], $provider->getCompletions(''));
        $this->assertSame(['101', '102.5'], $provider->getCompletions('10'));
    }

    public function testKeysAreDiscarded(): void
    {
        $provider = new ListCompletionProvider([5 => 'a', 9 => 'b']);

        $this->assertSame(['a', 'b'], $provider->getCompletions(''));
    }
}
