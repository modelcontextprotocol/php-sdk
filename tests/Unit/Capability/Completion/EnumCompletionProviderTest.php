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

use Mcp\Capability\Completion\EnumCompletionProvider;
use PHPUnit\Framework\TestCase;

enum CompletionFixture: string
{
    case First = 'alpha';
    case Second = 'alpine';
    case Third = 'algebra';
    case Zero = '0';
}

final class EnumCompletionProviderTest extends TestCase
{
    public function testPrefixMatchesKeepCaseOrder(): void
    {
        $provider = new EnumCompletionProvider(CompletionFixture::class);

        $this->assertSame(['alpha', 'alpine', 'algebra'], $provider->getCompletions('al'));
        $this->assertSame([], $provider->getCompletions('missing'));
    }

    public function testEmptyPrefixOffersAllCases(): void
    {
        $provider = new EnumCompletionProvider(CompletionFixture::class);

        $this->assertSame(['alpha', 'alpine', 'algebra', '0'], $provider->getCompletions(''));
    }

    public function testZeroIsMatchedAsPrefix(): void
    {
        $provider = new EnumCompletionProvider(CompletionFixture::class);

        $this->assertSame(['0'], $provider->getCompletions('0'));
    }
}
