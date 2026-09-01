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
use Mcp\Client\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    #[TestDox('the scope "0" is a scope like any other')]
    public function testKeepsTheScopeZero(): void
    {
        $token = AccessToken::fromResponse(['access_token' => 'token', 'scope' => '0  mcp:read']);

        $this->assertSame(['0', 'mcp:read'], $token->scopes);
    }

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

    #[TestWith([\PHP_INT_MAX])]
    #[TestWith(['99999999999999999999'])]
    #[TestWith(['1e30'])]
    #[TestWith([\INF])]
    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestDox('an expires_in that gives no usable expiry counts as not given')]
    public function testIgnoresAnUnusableExpiry(mixed $expiresIn): void
    {
        $this->assertNull(AccessToken::fromResponse(['access_token' => 'abc', 'expires_in' => $expiresIn], 1000)->expiresAt);
    }

    #[TestWith(["abc\r\nX-Evil: 1"])]
    #[TestWith(["abc\x7f"])]
    #[TestDox('a token that does not fit in a header is rejected')]
    public function testRejectsATokenThatDoesNotFitInAHeader(string $accessToken): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccessToken($accessToken);
    }

    #[TestWith(['mac'])]
    #[TestWith(['DPoP'])]
    #[TestWith([''])]
    #[TestWith(["Bearer\r\nX-Evil: 1"])]
    #[TestDox('a token type other than Bearer is rejected')]
    public function testRejectsAnotherTokenType(string $tokenType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('token type');

        new AccessToken('abc', $tokenType);
    }

    #[TestDox('the token type is matched case-insensitively and always sent as Bearer')]
    public function testAlwaysSendsBearer(): void
    {
        $this->assertSame('Bearer abc', (new AccessToken('abc', 'bearer'))->getAuthorizationHeader());
    }

    #[TestDox('a token expires at its expiry')]
    public function testExpires(): void
    {
        $token = new AccessToken('abc', expiresAt: 1000);

        $this->assertFalse($token->isExpired(999));
        $this->assertTrue($token->isExpired(1000));
    }

    #[TestDox('a token is expiring soon shortly before it actually expires')]
    public function testIsExpiringSoon(): void
    {
        $token = new AccessToken('abc', expiresAt: 1000);

        $this->assertFalse($token->isExpiringSoon(900));
        $this->assertTrue($token->isExpiringSoon(980));
        $this->assertTrue($token->isExpiringSoon(1001));
    }

    #[TestDox('a token without an expiry never goes stale on its own')]
    public function testNeverExpiresWithoutExpiry(): void
    {
        $this->assertFalse((new AccessToken('abc'))->isExpired());
        $this->assertFalse((new AccessToken('abc'))->isExpiringSoon());
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
        $token = new AccessToken('abc', 'Bearer', 4600, 'refresh', ['mcp:read'], 'client-1');
        $restored = AccessToken::fromArray($token->jsonSerialize());

        $this->assertEquals($token, $restored);
    }
}
