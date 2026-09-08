<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\Pkce;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
    #[TestDox('the challenge is the S256 hash of the verifier, base64url encoded')]
    public function testChallengeIsDerivedFromTheVerifier(): void
    {
        $pkce = Pkce::generate();
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce->verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $pkce->challenge);
        $this->assertSame('S256', Pkce::METHOD);
    }

    #[TestDox('the verifier fits the length and alphabet RFC 7636 allows')]
    public function testVerifierShape(): void
    {
        $verifier = Pkce::generate()->verifier;

        $this->assertGreaterThanOrEqual(43, \strlen($verifier));
        $this->assertLessThanOrEqual(128, \strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
    }

    #[TestDox('two pairs never come out the same')]
    public function testEachPairIsFresh(): void
    {
        $this->assertNotSame(Pkce::generate()->verifier, Pkce::generate()->verifier);
    }

    #[TestDox('a stored verifier reproduces the challenge it was sent with')]
    public function testFromVerifier(): void
    {
        $pkce = Pkce::generate();

        $this->assertSame($pkce->challenge, Pkce::fromVerifier($pkce->verifier)->challenge);
    }
}
