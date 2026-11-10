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

use Mcp\Server\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class AuthorizationResultTest extends TestCase
{
    #[TestWith([null])]
    #[TestWith([0])]
    #[TestWith([30])]
    public function testUnavailableKeepsTheDelay(?int $retryAfter): void
    {
        $result = AuthorizationResult::unavailable($retryAfter);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(503, $result->getStatusCode());
        $this->assertSame($retryAfter, $result->getRetryAfter());
    }

    public function testUnavailableRejectsNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationResult::unavailable(-1);
    }
}
