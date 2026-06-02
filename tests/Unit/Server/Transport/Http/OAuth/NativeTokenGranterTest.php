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

use Mcp\Exception\OAuthException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationCode;
use Mcp\Server\Transport\Http\OAuth\Client;
use Mcp\Server\Transport\Http\OAuth\InMemoryAuthorizationCodeStore;
use Mcp\Server\Transport\Http\OAuth\InMemoryClientRepository;
use Mcp\Server\Transport\Http\OAuth\InMemoryRefreshTokenStore;
use Mcp\Server\Transport\Http\OAuth\JwtAccessTokenIssuer;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\NativeAuthorizationCodeIssuer;
use Mcp\Server\Transport\Http\OAuth\NativeTokenGranter;
use Mcp\Server\Transport\Http\OAuth\Pkce;
use Mcp\Server\Transport\Http\OAuth\ResourceOwner;
use Mcp\Server\Transport\Http\OAuth\RsaSigningKey;
use Mcp\Server\Transport\Http\OAuth\StaticJwksProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class NativeTokenGranterTest extends TestCase
{
    private const ISSUER = 'https://mcp.example.com';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const REDIRECT = 'https://client.example.com/callback';

    private RsaSigningKey $signingKey;
    private InMemoryClientRepository $clients;
    private InMemoryAuthorizationCodeStore $codes;
    private InMemoryRefreshTokenStore $refreshTokens;
    private NativeTokenGranter $granter;
    private NativeAuthorizationCodeIssuer $codeIssuer;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->signingKey = new RsaSigningKey($pem, 'test-kid');

        $this->clients = new InMemoryClientRepository([
            new Client('public-client', null, [self::REDIRECT], ['authorization_code', 'refresh_token'], ['mcp:tools'], Client::AUTH_METHOD_NONE),
            new Client('conf-client', 'topsecret', [self::REDIRECT], ['authorization_code', 'refresh_token'], ['mcp:tools'], Client::AUTH_METHOD_CLIENT_SECRET_BASIC),
        ]);
        $this->codes = new InMemoryAuthorizationCodeStore();
        $this->refreshTokens = new InMemoryRefreshTokenStore();
        $this->codeIssuer = new NativeAuthorizationCodeIssuer($this->codes);

        $issuer = new JwtAccessTokenIssuer($this->signingKey, self::ISSUER);
        $this->granter = new NativeTokenGranter($this->clients, $this->codes, $this->refreshTokens, $issuer, resource: self::ISSUER);
    }

    #[TestDox('authorization_code grant issues a token that validates through JwtTokenValidator (self-issued round-trip)')]
    public function testAuthorizationCodeGrantRoundTrip(): void
    {
        $code = $this->issueCode('public-client');

        $response = $this->granter->grant('authorization_code', [
            'client_id' => 'public-client',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertSame('Bearer', $response->tokenType);
        $this->assertSame(['mcp:tools'], $response->scopes);
        $this->assertNotNull($response->refreshToken);

        $validator = new JwtTokenValidator(self::ISSUER, self::ISSUER, new StaticJwksProvider($this->signingKey));
        $result = $validator->validate($response->accessToken);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('user-1', $result->getAttributes()['oauth.subject']);
        $this->assertSame(['mcp:tools'], $result->getAttributes()['oauth.scopes']);
    }

    #[TestDox('authorization code is single-use')]
    public function testCodeIsSingleUse(): void
    {
        $code = $this->issueCode('public-client');
        $params = ['client_id' => 'public-client', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER];

        $this->granter->grant('authorization_code', $params);

        try {
            $this->granter->grant('authorization_code', $params);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
    }

    #[TestDox('rejects an invalid PKCE verifier')]
    public function testPkceMismatch(): void
    {
        $code = $this->issueCode('public-client');

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('PKCE');
        $this->granter->grant('authorization_code', [
            'client_id' => 'public-client',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => 'not-the-verifier',
        ]);
    }

    #[TestDox('rejects a mismatching redirect_uri')]
    public function testRedirectUriMismatch(): void
    {
        $code = $this->issueCode('public-client');

        $this->expectException(OAuthException::class);
        $this->granter->grant('authorization_code', [
            'client_id' => 'public-client',
            'code' => $code,
            'redirect_uri' => 'https://evil.example.com/callback',
            'code_verifier' => self::VERIFIER,
        ]);
    }

    #[TestDox('confidential client with a bad secret is rejected with invalid_client (401)')]
    public function testConfidentialClientBadSecret(): void
    {
        $code = $this->issueCode('conf-client');

        try {
            $this->granter->grant('authorization_code', [
                'client_id' => 'conf-client',
                'client_secret' => 'wrong',
                'code' => $code,
                'redirect_uri' => self::REDIRECT,
                'code_verifier' => self::VERIFIER,
            ]);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
            $this->assertSame(401, $e->httpStatus);
        }
    }

    #[TestDox('refresh tokens rotate: the old token is invalid after use')]
    public function testRefreshTokenRotation(): void
    {
        $code = $this->issueCode('public-client');
        $first = $this->granter->grant('authorization_code', [
            'client_id' => 'public-client',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ]);
        $this->assertNotNull($first->refreshToken);

        $second = $this->granter->grant('refresh_token', [
            'client_id' => 'public-client',
            'refresh_token' => $first->refreshToken,
        ]);
        $this->assertNotNull($second->refreshToken);
        $this->assertNotSame($first->refreshToken, $second->refreshToken);

        $this->expectException(OAuthException::class);
        $this->granter->grant('refresh_token', ['client_id' => 'public-client', 'refresh_token' => $first->refreshToken]);
    }

    #[TestDox('rejects an unsupported grant type')]
    public function testUnsupportedGrantType(): void
    {
        try {
            $this->granter->grant('password', ['client_id' => 'public-client']);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame('unsupported_grant_type', $e->error);
        }
    }

    #[TestDox('rejects an expired authorization code')]
    public function testExpiredCode(): void
    {
        $this->codes->store('expired', new AuthorizationCode(
            clientId: 'public-client',
            redirectUri: self::REDIRECT,
            scopes: ['mcp:tools'],
            codeChallenge: Pkce::challenge(self::VERIFIER),
            codeChallengeMethod: 'S256',
            userId: 'user-1',
            userClaims: [],
            resource: self::ISSUER,
            expiresAt: new \DateTimeImmutable('-1 minute'),
        ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('expired');
        $this->granter->grant('authorization_code', [
            'client_id' => 'public-client',
            'code' => 'expired',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ]);
    }

    private function issueCode(string $clientId): string
    {
        $client = $this->clients->find($clientId);
        $this->assertNotNull($client);

        return $this->codeIssuer->issueCode(
            $client,
            new ResourceOwner('user-1'),
            self::REDIRECT,
            ['mcp:tools'],
            Pkce::challenge(self::VERIFIER),
            'S256',
            self::ISSUER,
        );
    }
}
