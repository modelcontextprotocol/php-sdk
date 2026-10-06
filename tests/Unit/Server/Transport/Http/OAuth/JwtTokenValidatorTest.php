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

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class JwtTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://auth.example.com';
    private const AUDIENCE = 'https://mcp.example.com/mcp';

    private static string $privateKey;
    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        self::$privateKey = $privateKey;
        self::$publicKey = $details['key'];
    }

    public function testValidTokenYieldsAccessToken(): void
    {
        $result = $this->validator()->validate($this->token([
            'sub' => 'user-123',
            'client_id' => 'client-abc',
            'scope' => 'mcp:read mcp:write',
        ]));

        $this->assertTrue($result->isAllowed());
        $token = $result->getAccessToken();
        $this->assertNotNull($token);
        $this->assertSame(['mcp:read', 'mcp:write'], $token->getScopes());
        $this->assertSame('user-123', $token->getSubject());
        $this->assertSame('client-abc', $token->getClientId());
        $this->assertSame(self::ISSUER, $token->getClaim('iss'));
    }

    public function testScopesFromArrayClaim(): void
    {
        $result = $this->validator(scopeClaim: 'scp')->validate($this->token(['scp' => ['a', 'b', 3]]));

        $this->assertSame(['a', 'b'], $result->getAccessToken()?->getScopes());
    }

    public function testRejectsWrongAudience(): void
    {
        $result = $this->validator()->validate($this->token(['aud' => 'https://graph.example.com']));

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('Token audience mismatch.', $result->getErrorDescription());
    }

    public function testAcceptsAnyConfiguredAudience(): void
    {
        $validator = $this->validator(audience: ['api://client', self::AUDIENCE]);

        $this->assertTrue($validator->validate($this->token(['aud' => ['other', 'api://client']]))->isAllowed());
    }

    public function testRejectsWrongIssuer(): void
    {
        $result = $this->validator()->validate($this->token(['iss' => 'https://evil.example.com']));

        $this->assertSame('Token issuer mismatch.', $result->getErrorDescription());
    }

    public function testRejectsExpiredToken(): void
    {
        $result = $this->validator()->validate($this->token(['exp' => time() - 30]));

        $this->assertSame('Token has expired.', $result->getErrorDescription());
    }

    public function testLeewayToleratesClockSkew(): void
    {
        $this->assertTrue($this->validator(leeway: 60)->validate($this->token(['exp' => time() - 30]))->isAllowed());
        $this->assertSame(0, JWT::$leeway, 'the global leeway is restored');
    }

    public function testRejectsTokenNotYetValid(): void
    {
        $result = $this->validator()->validate($this->token(['nbf' => time() + 300]));

        $this->assertSame('Token is not yet valid.', $result->getErrorDescription());
    }

    public function testRejectsAlgorithmOutsideAllowlist(): void
    {
        $token = JWT::encode($this->claims([]), 'shared-secret-that-is-long-enough-for-hs256', 'HS256', 'kid-1');

        $result = $this->validator()->validate($token);

        $this->assertSame('Token algorithm is not accepted.', $result->getErrorDescription());
    }

    public function testRejectsBadSignatureWithoutLeakingDetails(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($other);
        openssl_pkey_export($other, $otherKey);

        $result = $this->validator()->validate(JWT::encode($this->claims([]), $otherKey, 'RS256', 'kid-1'));

        $this->assertSame('Token validation failed.', $result->getErrorDescription());
    }

    public function testRejectsUnknownKeyId(): void
    {
        $result = $this->validator()->validate(JWT::encode($this->claims([]), self::$privateKey, 'RS256', 'unknown'));

        $this->assertSame('Token validation failed.', $result->getErrorDescription());
    }

    public function testRejectsMalformedToken(): void
    {
        $this->assertSame('Token is malformed.', $this->validator()->validate('not-a-jwt')->getErrorDescription());
        $this->assertSame('Token is malformed.', $this->validator()->validate('a.b.c')->getErrorDescription());
    }

    public function testRequiredTokenType(): void
    {
        $validator = $this->validator(tokenType: 'at+jwt');

        $this->assertTrue($validator->validate($this->token([], ['typ' => 'at+jwt']))->isAllowed());
        $this->assertTrue($validator->validate($this->token([], ['typ' => 'application/AT+JWT']))->isAllowed());
        $this->assertSame('Token type is not accepted.', $validator->validate($this->token([], ['typ' => 'JWT']))->getErrorDescription());
        $this->assertSame('Token type is not accepted.', $validator->validate($this->token([]))->getErrorDescription());
    }

    public function testRequiresAudienceAndAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JwtTokenValidator(self::ISSUER, [], ['kid-1' => new Key(self::$publicKey, 'RS256')]);
    }

    public function testFromIssuerDiscoversAndCachesKeys(): void
    {
        $factory = new Psr17Factory();
        $client = $this->jwksClient($factory);

        $cache = new ArrayAdapter();
        $validator = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, $cache, $client, $factory);
        $this->assertTrue($validator->validate($this->token([]))->isAllowed());

        $again = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, $cache, $client, $factory);
        $this->assertTrue($again->validate($this->token([]))->isAllowed());

        $this->assertSame([
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/jwks',
        ], $client->requested);
    }

    public function testUnknownKeyIdInJwksIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        $keys = new CachedKeySet('https://auth.example.com/jwks', $this->jwksClient($factory), $factory, new ArrayAdapter(), 3600, true);
        $validator = new JwtTokenValidator(self::ISSUER, self::AUDIENCE, $keys);

        $result = $validator->validate(JWT::encode($this->claims([]), self::$privateKey, 'RS256', 'rotated'));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('Token signing key could not be resolved.', $result->getErrorDescription());
    }

    public function testUnreachableJwksIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('Connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
        $keys = new CachedKeySet('https://auth.example.com/jwks', $client, $factory, new ArrayAdapter(), 3600, true);
        $validator = new JwtTokenValidator(self::ISSUER, self::AUDIENCE, $keys);

        $result = $validator->validate($this->token([]));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('Token signing key could not be resolved.', $result->getErrorDescription());
    }

    /**
     * Serves the issuer's metadata and a JWKS holding the test key, without `alg` like Entra.
     */
    private function jwksClient(Psr17Factory $factory): ClientInterface
    {
        $modulus = openssl_pkey_get_details(openssl_pkey_get_public(self::$publicKey))['rsa'] ?? [];
        $jwk = [
            'kty' => 'RSA',
            'kid' => 'kid-1',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($modulus['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($modulus['e']), '+/', '-_'), '='),
        ];

        return new class($factory, $jwk) implements ClientInterface {
            /** @var list<string> */
            public array $requested = [];

            /** @param array<string, string> $jwk */
            public function __construct(private Psr17Factory $factory, private array $jwk)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $url = (string) $request->getUri();
                $this->requested[] = $url;

                $body = match ($url) {
                    'https://auth.example.com/.well-known/oauth-authorization-server' => ['issuer' => 'https://auth.example.com', 'jwks_uri' => 'https://auth.example.com/jwks'],
                    'https://auth.example.com/jwks' => ['keys' => [$this->jwk]],
                    default => null,
                };

                return null === $body
                    ? $this->factory->createResponse(404)
                    : $this->factory->createResponse(200)->withBody($this->factory->createStream(json_encode($body, \JSON_THROW_ON_ERROR)));
            }
        };
    }

    /**
     * @param string|list<string> $audience
     */
    private function validator(string|array $audience = self::AUDIENCE, string $scopeClaim = 'scope', ?string $tokenType = null, int $leeway = 0): JwtTokenValidator
    {
        return new JwtTokenValidator(
            issuer: self::ISSUER,
            audience: $audience,
            keys: ['kid-1' => new Key(self::$publicKey, 'RS256')],
            scopeClaim: $scopeClaim,
            tokenType: $tokenType,
            leeway: $leeway,
        );
    }

    /**
     * @param array<string, mixed>  $claims
     * @param array<string, string> $header
     */
    private function token(array $claims, array $header = []): string
    {
        return JWT::encode($this->claims($claims), self::$privateKey, 'RS256', 'kid-1', $header);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private function claims(array $claims): array
    {
        return array_merge(['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'iat' => time(), 'exp' => time() + 600], $claims);
    }
}
