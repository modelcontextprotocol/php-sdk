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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testRejectsTokenWithoutExpiration(): void
    {
        $claims = $this->claims([]);
        unset($claims['exp']);

        $result = $this->validator()->validate(JWT::encode($claims, self::$privateKey, 'RS256', 'kid-1'));

        $this->assertSame('Token has no expiration.', $result->getErrorDescription());
    }

    /**
     * @param list<string> $errors php-jwt 7.x rejects "never" on decode, 6.x leaves it to the validator
     */
    #[DataProvider('provideNonNumericExpiration')]
    public function testRejectsNonNumericExpiration(string $exp, array $errors): void
    {
        $result = $this->validator()->validate($this->signedWithoutEncodeChecks($this->claims(['exp' => $exp])));

        $this->assertFalse($result->isAllowed());
        $this->assertContains($result->getErrorDescription(), $errors);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideNonNumericExpiration(): iterable
    {
        yield 'non-numeric string' => ['never', ['Token validation failed.', 'Token expiration is not a number.']];
        // Passes php-jwt's is_numeric() check in every version, so only the validator rejects it.
        yield 'numeric string' => [(string) (time() + 600), ['Token expiration is not a number.']];
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
        $requested = new \ArrayObject();
        $client = $this->jwksClient($factory, requested: $requested);

        $cache = new ArrayAdapter();
        $validator = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, $cache, $client, $factory);
        $this->assertTrue($validator->validate($this->token([]))->isAllowed());

        $again = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, $cache, $client, $factory);
        $this->assertTrue($again->validate($this->token([]))->isAllowed());

        $this->assertSame([
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/jwks',
        ], $requested->getArrayCopy());
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

    public function testFromIssuerTagsKeysWithoutAlgorithmPerToken(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($ec);
        openssl_pkey_export($ec, $ecPrivateKey);
        $ecDetails = openssl_pkey_get_details($ec);
        $rsaDetails = openssl_pkey_get_details(openssl_pkey_get_public(self::$publicKey));
        $this->assertIsArray($ecDetails);
        $this->assertIsArray($rsaDetails);

        $factory = new Psr17Factory();
        $client = $this->jwksClient($factory, [
            ['kty' => 'RSA', 'kid' => 'kid-1', 'n' => self::base64Url($rsaDetails['rsa']['n']), 'e' => self::base64Url($rsaDetails['rsa']['e'])],
            ['kty' => 'EC', 'kid' => 'ec-1', 'crv' => 'P-256', 'x' => self::base64Url($ecDetails['ec']['x']), 'y' => self::base64Url($ecDetails['ec']['y'])],
        ]);
        $validator = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, new ArrayAdapter(), $client, $factory, ['RS256', 'ES256']);

        $this->assertTrue($validator->validate($this->token([]))->isAllowed());
        $this->assertTrue($validator->validate(JWT::encode($this->claims([]), $ecPrivateKey, 'ES256', 'ec-1'))->isAllowed());
    }

    public function testFromIssuerDiscoversOnFirstToken(): void
    {
        $factory = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('Connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        $validator = JwtTokenValidator::fromIssuer(self::ISSUER, self::AUDIENCE, new ArrayAdapter(), $client, $factory);
        $result = $validator->validate($this->token([]));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('Token signing key could not be resolved.', $result->getErrorDescription());
    }

    public function testFromIssuerRejectsInsecureIssuer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JwtTokenValidator::fromIssuer('http://auth.example.com', self::AUDIENCE, new ArrayAdapter(), $this->jwksClient(new Psr17Factory()), new Psr17Factory());
    }

    /**
     * Serves the issuer's metadata and a JWKS holding the given keys, by default the test key without `alg` like Entra.
     *
     * @param list<array<string, string>>|null $jwks
     * @param \ArrayObject<int, string>|null   $requested collects the requested URLs
     */
    private function jwksClient(Psr17Factory $factory, ?array $jwks = null, ?\ArrayObject $requested = null): ClientInterface
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(self::$publicKey));
        $jwks ??= [[
            'kty' => 'RSA',
            'kid' => 'kid-1',
            'use' => 'sig',
            'n' => self::base64Url($details['rsa']['n'] ?? ''),
            'e' => self::base64Url($details['rsa']['e'] ?? ''),
        ]];

        return new class($factory, $jwks, $requested ?? new \ArrayObject()) implements ClientInterface {
            /**
             * @param list<array<string, string>> $jwks
             * @param \ArrayObject<int, string>   $requested
             */
            public function __construct(private Psr17Factory $factory, private array $jwks, private \ArrayObject $requested)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $url = (string) $request->getUri();
                $this->requested[] = $url;

                $body = match ($url) {
                    'https://auth.example.com/.well-known/oauth-authorization-server' => ['issuer' => 'https://auth.example.com', 'jwks_uri' => 'https://auth.example.com/jwks'],
                    'https://auth.example.com/jwks' => ['keys' => $this->jwks],
                    default => null,
                };

                return null === $body
                    ? $this->factory->createResponse(404)
                    : $this->factory->createResponse(200)->withBody($this->factory->createStream(json_encode($body, \JSON_THROW_ON_ERROR)));
            }
        };
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
     * Signs like JWT::encode(), which rejects non-numeric registered claims since php-jwt 7.1.
     *
     * @param array<string, mixed> $claims
     */
    private function signedWithoutEncodeChecks(array $claims): string
    {
        $message = JWT::urlsafeB64Encode(JWT::jsonEncode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'kid-1']))
            .'.'.JWT::urlsafeB64Encode(JWT::jsonEncode($claims));

        return $message.'.'.JWT::urlsafeB64Encode(JWT::sign($message, self::$privateKey, 'RS256'));
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
