<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Auth;

use Mcp\Server\Auth\AuthenticationResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\AuthenticationResult
 */
final class AuthenticationResultTest extends TestCase
{
    public function testAuthenticatedResult(): void
    {
        $claims = [
            'sub' => 'user-123',
            'scope' => 'read write admin',
            'aud' => 'https://mcp.example.com',
        ];

        $result = AuthenticationResult::authenticated($claims, ['token_type' => 'jwt']);

        $this->assertTrue($result->authenticated);
        $this->assertSame('user-123', $result->getSubject());
        $this->assertSame(['read', 'write', 'admin'], $result->getScopes());
        $this->assertNull($result->error);
        $this->assertNull($result->errorDescription);
        $this->assertSame(['token_type' => 'jwt'], $result->context);
    }

    public function testUnauthenticatedResult(): void
    {
        $result = AuthenticationResult::unauthenticated('invalid_token', 'Token has expired');

        $this->assertFalse($result->authenticated);
        $this->assertSame('invalid_token', $result->error);
        $this->assertSame('Token has expired', $result->errorDescription);
        $this->assertEmpty($result->claims);
        $this->assertNull($result->getSubject());
        $this->assertEmpty($result->getScopes());
    }

    public function testHasScope(): void
    {
        $result = AuthenticationResult::authenticated(['scope' => 'read write']);

        $this->assertTrue($result->hasScope('read'));
        $this->assertTrue($result->hasScope('write'));
        $this->assertFalse($result->hasScope('admin'));
    }

    public function testHasAllScopes(): void
    {
        $result = AuthenticationResult::authenticated(['scope' => 'read write admin']);

        $this->assertTrue($result->hasAllScopes(['read', 'write']));
        $this->assertTrue($result->hasAllScopes(['read', 'write', 'admin']));
        $this->assertFalse($result->hasAllScopes(['read', 'delete']));
    }

    public function testScopesAsArray(): void
    {
        $result = AuthenticationResult::authenticated(['scope' => ['read', 'write']]);

        $this->assertSame(['read', 'write'], $result->getScopes());
        $this->assertTrue($result->hasScope('read'));
    }

    public function testEmptyScopes(): void
    {
        $result = AuthenticationResult::authenticated(['sub' => 'user-123']);

        $this->assertEmpty($result->getScopes());
        $this->assertFalse($result->hasScope('read'));
    }
}

