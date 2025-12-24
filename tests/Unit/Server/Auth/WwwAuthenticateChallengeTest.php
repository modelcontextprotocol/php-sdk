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

use Mcp\Server\Auth\WwwAuthenticateChallenge;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\WwwAuthenticateChallenge
 */
final class WwwAuthenticateChallengeTest extends TestCase
{
    public function testBasicChallenge(): void
    {
        $challenge = new WwwAuthenticateChallenge();

        $this->assertSame('Bearer', $challenge->build());
    }

    public function testChallengeWithRealm(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withRealm('MCP Server');

        $this->assertSame('Bearer realm="MCP Server"', $challenge->build());
    }

    public function testChallengeWithError(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withError('invalid_token', 'Token has expired');

        $this->assertSame(
            'Bearer error="invalid_token", error_description="Token has expired"',
            $challenge->build()
        );
    }

    public function testChallengeWithScope(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withScope('read write');

        $this->assertSame('Bearer scope="read write"', $challenge->build());
    }

    public function testChallengeWithScopeArray(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withScope(['read', 'write', 'admin']);

        $this->assertSame('Bearer scope="read write admin"', $challenge->build());
    }

    public function testChallengeWithResourceMetadata(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withResourceMetadata('https://mcp.example.com/.well-known/oauth-protected-resource');

        $this->assertSame(
            'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"',
            $challenge->build()
        );
    }

    public function testForUnauthorized(): void
    {
        $challenge = WwwAuthenticateChallenge::forUnauthorized(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            'read write',
            'Missing token'
        );

        $result = $challenge->build();

        $this->assertStringContainsString('error="invalid_token"', $result);
        $this->assertStringContainsString('scope="read write"', $result);
        $this->assertStringContainsString('resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"', $result);
    }

    public function testForInsufficientScope(): void
    {
        $challenge = WwwAuthenticateChallenge::forInsufficientScope(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            ['admin', 'write'],
            'Admin access required'
        );

        $result = $challenge->build();

        $this->assertStringContainsString('error="insufficient_scope"', $result);
        $this->assertStringContainsString('scope="admin write"', $result);
        $this->assertStringContainsString('error_description="Admin access required"', $result);
    }

    public function testToString(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withRealm('Test');

        $this->assertSame('Bearer realm="Test"', (string) $challenge);
    }

    public function testEscapesQuotes(): void
    {
        $challenge = (new WwwAuthenticateChallenge())
            ->withRealm('Test "realm"');

        $this->assertSame('Bearer realm="Test \"realm\""', $challenge->build());
    }

    public function testImmutability(): void
    {
        $original = new WwwAuthenticateChallenge();
        $withRealm = $original->withRealm('Test');

        $this->assertNotSame($original, $withRealm);
        $this->assertSame('Bearer', $original->build());
        $this->assertSame('Bearer realm="Test"', $withRealm->build());
    }
}

