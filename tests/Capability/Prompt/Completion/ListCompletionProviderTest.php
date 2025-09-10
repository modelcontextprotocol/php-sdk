<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Prompt\Completion;

use Mcp\Capability\Prompt\Completion\ListCompletionProvider;
use PHPUnit\Framework\TestCase;

class ListCompletionProviderTest extends TestCase
{
    public function testReturnsAllValuesWhenCurrentValueIsEmpty(): void
    {
        $values = ['apple', 'banana', 'cherry'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('');

        $this->assertSame($values, $result);
    }

    public function testFiltersValuesBasedOnCurrentValuePrefix(): void
    {
        $values = ['apple', 'apricot', 'banana', 'cherry'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('ap');

        $this->assertSame(['apple', 'apricot'], $result);
    }

    public function testReturnsEmptyArrayWhenNoValuesMatch(): void
    {
        $values = ['apple', 'banana', 'cherry'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('xyz');

        $this->assertSame([], $result);
    }

    public function testWorksWithSingleCharacterPrefix(): void
    {
        $values = ['apple', 'banana', 'cherry'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('a');

        $this->assertSame(['apple'], $result);
    }

    public function testIsCaseSensitiveByDefault(): void
    {
        $values = ['Apple', 'apple', 'APPLE'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('A');

        $this->assertEquals(['Apple', 'APPLE'], $result);
    }

    public function testHandlesEmptyValuesArray(): void
    {
        $provider = new ListCompletionProvider([]);
        $result = $provider->getCompletions('test');

        $this->assertSame([], $result);
    }

    public function testPreservesArrayOrder(): void
    {
        $values = ['zebra', 'apple', 'banana'];
        $provider = new ListCompletionProvider($values);
        $result = $provider->getCompletions('');

        $this->assertSame(['zebra', 'apple', 'banana'], $result);
    }
}
