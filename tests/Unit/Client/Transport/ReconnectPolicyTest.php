<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Transport;

use Mcp\Client\Transport\ReconnectPolicy;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ReconnectPolicyTest extends TestCase
{
    #[TestDox('the fallback delay doubles from the initial delay up to the cap')]
    public function testBackoff(): void
    {
        $policy = new ReconnectPolicy(initialDelayMs: 1_000, maxDelayMs: 10_000);

        $this->assertSame(
            [1_000, 2_000, 4_000, 8_000, 10_000, 10_000],
            array_map($policy->delayMs(...), range(0, 5)),
        );
    }

    #[TestDox('a server-provided retry value wins and is not capped')]
    public function testServerRetryWins(): void
    {
        $policy = new ReconnectPolicy(maxDelayMs: 10_000);

        $this->assertSame(60_000, $policy->delayMs(3, 60_000));
        $this->assertSame(0, $policy->delayMs(3, 0));
    }

    #[TestDox('a negative attempt count is rejected')]
    public function testRejectsNegativeAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReconnectPolicy(maxAttempts: -1);
    }

    #[TestDox('a negative initial delay is rejected')]
    public function testRejectsNegativeInitialDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReconnectPolicy(initialDelayMs: -1);
    }

    #[TestDox('the maximum delay cannot be lower than the initial delay')]
    public function testRejectsDelayCapBelowInitialDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReconnectPolicy(initialDelayMs: 1_001, maxDelayMs: 1_000);
    }
}
