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

use Mcp\Server\Transport\Http\OAuth\Pkce;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase
{
    #[TestDox('computes the S256 challenge from the RFC 7636 Appendix B test vector')]
    public function testChallengeMatchesRfcVector(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertSame($expectedChallenge, Pkce::challenge($verifier));
    }

    #[TestDox('verifies a matching verifier and rejects a mismatching one')]
    public function testVerify(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = Pkce::challenge($verifier);

        $this->assertTrue(Pkce::verify($verifier, $challenge));
        $this->assertFalse(Pkce::verify('wrong-verifier', $challenge));
    }
}
