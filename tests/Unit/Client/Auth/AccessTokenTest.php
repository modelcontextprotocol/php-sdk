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

use Mcp\Client\Auth\AccessToken;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    #[TestDox('a token response is turned into an absolute expiry')]
    public function testFromResponse(): void
    {
        $token = AccessToken::fromResponse([
            'access_token' => 'abc',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'refresh',
            'scope' => 'mcp:read mcp:write',
        ], 1000);

        $this->assertSame('abc', $token->accessToken);
        $this->assertSame(4600, $token->expiresAt);
        $this->assertSame('refresh', $token->refreshToken);
        $this->assertSame(['mcp:read', 'mcp:write'], $token->scopes);
        $this->assertSame('Bearer abc', $token->getAuthorizationHeader());
    }

    #[TestDox('a response without an access token is rejected')]
    public function testRejectsResponseWithoutToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AccessToken::fromResponse(['token_type' => 'Bearer']);
    }

    #[TestDox('a token is treated as expired shortly before it actually is')]
    public function testExpiresEarly(): void
    {
        $token = new AccessToken('abc', expiresAt: 1000);

        $this->assertFalse($token->isExpired(900));
        $this->assertTrue($token->isExpired(980));
        $this->assertTrue($token->isExpired(1001));
    }

    #[TestDox('a token without an expiry never goes stale on its own')]
    public function testNeverExpiresWithoutExpiry(): void
    {
        $this->assertFalse((new AccessToken('abc'))->isExpired());
    }

    #[TestDox('scope coverage decides whether a challenge needs a new authorization')]
    public function testCovers(): void
    {
        $token = new AccessToken('abc', scopes: ['mcp:read']);

        $this->assertTrue($token->covers(['mcp:read']));
        $this->assertTrue($token->covers([]));
        $this->assertFalse($token->covers(['mcp:read', 'mcp:write']));
    }

    #[TestDox('a token whose grant was not reported covers whatever was asked for')]
    public function testUnreportedScopeCoversEverything(): void
    {
        $this->assertTrue((new AccessToken('abc'))->covers(['mcp:write']));
    }

    #[TestDox('the requested scopes are recorded when the server did not report them')]
    public function testWithGrantedScopes(): void
    {
        $this->assertSame(['mcp:read'], (new AccessToken('abc'))->withGrantedScopes(['mcp:read'])->scopes);
        $this->assertSame(['mcp:read'], (new AccessToken('abc', scopes: ['mcp:read']))->withGrantedScopes(['mcp:write'])->scopes);
    }

    #[TestDox('a token survives a round trip through storage')]
    public function testRoundTrip(): void
    {
        $token = new AccessToken('abc', 'Bearer', 4600, 'refresh', ['mcp:read']);
        $restored = AccessToken::fromArray($token->jsonSerialize());

        $this->assertEquals($token, $restored);
    }
}
