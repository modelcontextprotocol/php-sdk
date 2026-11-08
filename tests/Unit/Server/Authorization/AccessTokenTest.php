<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Authorization;

use Mcp\Server\Authorization\AccessToken;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    public function testExposesScopesAndClaims(): void
    {
        $token = new AccessToken(['a', 'b'], ['sub' => 'user', 'client_id' => 'client', 'azp' => 'other']);

        $this->assertSame(['a', 'b'], $token->getScopes());
        $this->assertTrue($token->hasScope('a'));
        $this->assertFalse($token->hasScope('c'));
        $this->assertSame('user', $token->getSubject());
        $this->assertSame('client', $token->getClientId());
        $this->assertNull($token->getClaim('missing'));
    }

    public function testFallsBackToAuthorizedParty(): void
    {
        $this->assertSame('other', (new AccessToken([], ['azp' => 'other']))->getClientId());
        $this->assertNull((new AccessToken([], ['sub' => 42]))->getSubject());
    }

    public function testPrincipalOutlivesARefresh(): void
    {
        $principal = (new AccessToken(['read'], ['iss' => 'https://as', 'sub' => 'alice', 'azp' => 'ide', 'jti' => '1']))->getPrincipal();

        $this->assertSame($principal, (new AccessToken(['read', 'write'], ['iss' => 'https://as', 'sub' => 'alice', 'azp' => 'ide', 'jti' => '2']))->getPrincipal());
        $this->assertSame($principal, (new AccessToken([], ['iss' => 'https://as', 'sub' => 'alice', 'client_id' => 'ide']))->getPrincipal());
    }

    public function testPrincipalDiffersByIssuerClientAndSubject(): void
    {
        $principal = (new AccessToken([], ['iss' => 'https://as', 'sub' => 'alice', 'azp' => 'ide']))->getPrincipal();

        $this->assertNotSame($principal, (new AccessToken([], ['iss' => 'https://other', 'sub' => 'alice', 'azp' => 'ide']))->getPrincipal());
        $this->assertNotSame($principal, (new AccessToken([], ['iss' => 'https://as', 'sub' => 'alice', 'azp' => 'cli']))->getPrincipal());
        $this->assertNotSame($principal, (new AccessToken([], ['iss' => 'https://as', 'sub' => 'carol', 'azp' => 'ide']))->getPrincipal());
    }

    public function testPrincipalKeepsClaimsApart(): void
    {
        $this->assertNotSame(
            (new AccessToken([], ['client_id' => 'a', 'sub' => 'b']))->getPrincipal(),
            (new AccessToken([], ['client_id' => 'b', 'sub' => 'a']))->getPrincipal(),
        );
    }

    public function testPrincipalKeepsClaimsThatAreNotUtf8Apart(): void
    {
        $this->assertNotSame(
            (new AccessToken([], ['sub' => "\xB1"]))->getPrincipal(),
            (new AccessToken([], ['sub' => "\xB2"]))->getPrincipal(),
        );
    }

    public function testIsNeverSerialized(): void
    {
        $this->assertSame('{"token":null}', json_encode(['token' => new AccessToken(['a'], ['sub' => 'user'])]));
    }
}
