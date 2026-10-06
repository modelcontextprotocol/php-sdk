<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\OAuth;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use PHPUnit\Framework\TestCase;

final class ScopePolicyTest extends TestCase
{
    public function testDefaultScopesApplyWithoutPayload(): void
    {
        $policy = new ScopePolicy(default: ['mcp']);

        $this->assertFalse($policy->inspectsBody());
        $this->assertSame(['mcp'], $policy->requiredFor());
    }

    public function testCombinesDefaultMethodAndToolScopes(): void
    {
        $policy = new ScopePolicy(
            default: ['mcp'],
            methods: ['tools/call' => ['tools'], 'resources/read' => ['resources']],
            tools: ['delete_file' => ['files:write'], 'read_file' => ['files:read']],
        );

        $this->assertTrue($policy->inspectsBody());
        $this->assertSame(['mcp', 'tools', 'files:write'], $policy->requiredFor([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'delete_file'],
        ]));
        $this->assertSame(['mcp'], $policy->requiredFor(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    }

    public function testBatchNeedsUnionOfItsMessages(): void
    {
        $policy = new ScopePolicy(methods: ['resources/read' => ['resources']], tools: ['read_file' => ['files:read']]);

        $this->assertSame(['files:read', 'resources'], $policy->requiredFor([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'read_file']],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'file:///a']],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'read_file']],
        ]));
    }

    public function testDeduplicatesAndKeepsNumericScopesStrings(): void
    {
        $policy = new ScopePolicy(default: ['mcp', 'mcp', '42'], methods: ['tools/list' => ['42', 'tools']]);

        $this->assertSame(['mcp', '42', 'tools'], $policy->requiredFor(['method' => 'tools/list']));
    }

    public function testToolScopesOnlyApplyToToolCalls(): void
    {
        $policy = new ScopePolicy(tools: ['greeting' => ['tools']]);

        $this->assertSame([], $policy->requiredFor(['method' => 'prompts/get', 'params' => ['name' => 'greeting']]));
    }

    public function testIgnoresMalformedPayloads(): void
    {
        $policy = new ScopePolicy(default: ['mcp'], methods: ['tools/call' => ['tools']]);

        $this->assertSame(['mcp'], $policy->requiredFor('garbage'));
        $this->assertSame(['mcp'], $policy->requiredFor([['method' => 42], 'x']));
    }

    public function testExpandWithoutHierarchyKeepsGrantedScopes(): void
    {
        $policy = new ScopePolicy();

        $this->assertSame([], $policy->expand([]));
        $this->assertSame(['a', 'c', 'b'], $policy->expand(['b', 'c', 'a', 'a']));
    }

    public function testExpandFollowsScopeHierarchy(): void
    {
        $policy = new ScopePolicy(implies: [
            'files:admin' => ['files:write'],
            'files:write' => ['files:read'],
            'loop' => ['loop'],
        ]);

        $this->assertSame(['files:admin', 'files:write', 'files:read'], $policy->expand(['files:admin']));
        $this->assertSame(['files:write', 'files:read'], $policy->expand(['files:write']));
        $this->assertSame(['loop'], $policy->expand(['loop']));
    }

    public function testRejectsInvalidScopes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScopePolicy(tools: ['x' => ['has space']]);
    }
}
