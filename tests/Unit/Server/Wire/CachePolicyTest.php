<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Wire;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Server\Wire\Rev2026Codec;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class CachePolicyTest extends TestCase
{
    #[TestDox('the default policy says nothing is fresh and nothing is shared')]
    public function testNoneIsConservative(): void
    {
        $policy = CachePolicy::none();

        $this->assertSame(0, $policy->ttlFor('tools/list'));
        $this->assertSame(CacheScope::Private, $policy->scopeFor('tools/list'));
    }

    #[TestDox('a default applies to every method')]
    public function testDefaultApplies(): void
    {
        $policy = CachePolicy::default(60_000, CacheScope::Public);

        $this->assertSame(60_000, $policy->ttlFor('tools/list'));
        $this->assertSame(60_000, $policy->ttlFor('resources/read'));
        $this->assertSame(CacheScope::Public, $policy->scopeFor('resources/read'));
    }

    #[TestDox('a per-method override wins, and leaves the others alone')]
    public function testPerMethodOverride(): void
    {
        $policy = CachePolicy::default(60_000)
            ->withMethod('tools/list', 3_600_000, CacheScope::Public);

        $this->assertSame(3_600_000, $policy->ttlFor('tools/list'));
        $this->assertSame(CacheScope::Public, $policy->scopeFor('tools/list'));
        $this->assertSame(60_000, $policy->ttlFor('resources/read'));
        $this->assertSame(CacheScope::Private, $policy->scopeFor('resources/read'));
    }

    #[TestDox('the policy is immutable')]
    public function testWithMethodDoesNotMutate(): void
    {
        $base = CachePolicy::default(1_000);
        $narrowed = $base->withMethod('tools/list', 5_000);

        $this->assertSame(1_000, $base->ttlFor('tools/list'));
        $this->assertSame(5_000, $narrowed->ttlFor('tools/list'));
    }

    #[TestDox('a negative TTL is refused: the spec requires zero or more')]
    public function testNegativeTtlIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CachePolicy::default(-1);
    }

    #[TestDox('a negative per-method TTL is refused too')]
    public function testNegativePerMethodTtlIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CachePolicy::default(0)->withMethod('tools/list', -5);
    }

    #[TestDox('the codec stamps the policy onto a cacheable result')]
    public function testCodecAppliesThePolicy(): void
    {
        $codec = new Rev2026Codec(null, CachePolicy::default(60_000)->withMethod('tools/list', 3_600_000, CacheScope::Public));

        $tools = $codec->encodeResult('tools/list', ['tools' => []]);
        $this->assertSame(3_600_000, $tools['ttlMs']);
        $this->assertSame('public', $tools['cacheScope']);

        $read = $codec->encodeResult('resources/read', ['contents' => []]);
        $this->assertSame(60_000, $read['ttlMs']);
        $this->assertSame('private', $read['cacheScope']);
    }

    #[TestDox('a value the result carries wins over the policy')]
    public function testAuthoredValueWins(): void
    {
        $codec = new Rev2026Codec(null, CachePolicy::default(60_000, CacheScope::Public));

        $encoded = $codec->encodeResult('resources/read', ['contents' => [], 'ttlMs' => 500, 'cacheScope' => 'private']);

        $this->assertSame(500, $encoded['ttlMs']);
        $this->assertSame('private', $encoded['cacheScope']);
    }

    #[TestDox('a method the spec does not make cacheable is left alone')]
    public function testNonCacheableMethodIsUntouched(): void
    {
        $encoded = (new Rev2026Codec(null, CachePolicy::default(60_000)))->encodeResult('tools/call', ['content' => []]);

        $this->assertArrayNotHasKey('ttlMs', $encoded);
        $this->assertArrayNotHasKey('cacheScope', $encoded);
    }
}
