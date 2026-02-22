<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthMicrosoft\Tests\Unit;

use Firebase\JWT\JWT;
use Mcp\Example\Server\OAuthMicrosoft\MicrosoftJwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests MicrosoftJwtTokenValidator for Graph and non-Graph token flows.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class MicrosoftJwtTokenValidatorTest extends TestCase
{
    #[TestDox('non-Graph Microsoft token is validated via JWKS')]
    public function testNonGraphTokenUsesStandardJwtValidation(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $jwksUri = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';
        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $jwtTokenValidator = new JwtTokenValidator(
            issuer: 'https://login.microsoftonline.com/tenant-id/v2.0',
            audience: 'mcp-api',
            jwksProvider: new JwksProvider(httpClient: $httpClient, requestFactory: $factory),
            jwksUri: $jwksUri,
            scopeClaim: 'scp',
        );
        $validator = new MicrosoftJwtTokenValidator(jwtTokenValidator: $jwtTokenValidator);

        $token = JWT::encode(
            [
                'iss' => 'https://login.microsoftonline.com/tenant-id/v2.0',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'scp' => 'files.read files.write',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($token);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(['files.read', 'files.write'], $result->getAttributes()['oauth.scopes']);
        $this->assertSame('user-123', $result->getAttributes()['oauth.subject']);
        $this->assertArrayNotHasKey('oauth.graph_token', $result->getAttributes());
    }

    #[TestDox('Graph token with nonce header is validated by claims only')]
    public function testGraphTokenWithNonceHeaderIsAllowed(): void
    {
        $factory = new Psr17Factory();
        $token = $this->buildGraphToken([
            'iss' => 'https://login.microsoftonline.com/tenant-id/v2.0',
            'aud' => 'mcp-api',
            'sub' => 'user-graph',
            'scp' => 'files.read files.write',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ]);

        $jwtTokenValidator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksProvider: new JwksProvider(
                httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
                requestFactory: $factory
            ),
            jwksUri: 'https://unused.example.com/jwks',
            scopeClaim: 'scp',
        );
        $validator = new MicrosoftJwtTokenValidator(
            jwtTokenValidator: $jwtTokenValidator,
            scopeClaim: 'scp',
        );

        $result = $validator->validate($token);

        $this->assertTrue($result->isAllowed());
        $this->assertTrue($result->getAttributes()['oauth.graph_token']);
        $this->assertSame(['files.read', 'files.write'], $result->getAttributes()['oauth.scopes']);
        $this->assertSame('user-graph', $result->getAttributes()['oauth.subject']);
    }

    #[TestDox('Graph token with invalid payload is unauthorized')]
    public function testGraphTokenInvalidPayloadIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        $header = $this->b64urlEncode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
            'nonce' => 'abc',
        ], \JSON_THROW_ON_ERROR));
        $token = $header.'..';

        $jwtTokenValidator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksProvider: new JwksProvider(
                httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
                requestFactory: $factory
            ),
            jwksUri: 'https://unused.example.com/jwks',
            scopeClaim: 'scp',
        );
        $validator = new MicrosoftJwtTokenValidator(
            jwtTokenValidator: $jwtTokenValidator,
            scopeClaim: 'scp',
        );

        $result = $validator->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Invalid token payload.', $result->getErrorDescription());
    }

    #[TestDox('Graph token with invalid issuer is unauthorized')]
    public function testGraphTokenInvalidIssuerIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        $token = $this->buildGraphToken([
            'iss' => 'https://evil.example.com',
            'aud' => 'mcp-api',
            'sub' => 'user-graph',
            'scp' => 'files.read',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ]);

        $jwtTokenValidator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksProvider: new JwksProvider(
                httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
                requestFactory: $factory
            ),
            jwksUri: 'https://unused.example.com/jwks',
            scopeClaim: 'scp',
        );
        $validator = new MicrosoftJwtTokenValidator(
            jwtTokenValidator: $jwtTokenValidator,
            scopeClaim: 'scp',
        );

        $result = $validator->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Invalid token issuer for Graph token.', $result->getErrorDescription());
    }

    #[TestDox('scope checks are delegated to base JwtTokenValidator')]
    public function testRequireScopesDelegatesToJwtTokenValidator(): void
    {
        $factory = new Psr17Factory();
        $jwtTokenValidator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksProvider: new JwksProvider(
                httpClient: $this->createHttpClientMock([], 0),
                requestFactory: $factory,
            ),
            jwksUri: 'https://unused.example.com/jwks',
            scopeClaim: 'scp',
        );
        $validator = new MicrosoftJwtTokenValidator(
            jwtTokenValidator: $jwtTokenValidator,
            scopeClaim: 'scp',
        );

        $result = AuthorizationResult::allow([
            'oauth.scopes' => ['files.read'],
        ]);
        $scoped = $validator->requireScopes($result, ['files.read', 'files.write']);

        $this->assertFalse($scoped->isAllowed());
        $this->assertSame(403, $scoped->getStatusCode());
        $this->assertSame('insufficient_scope', $scoped->getError());
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function buildGraphToken(array $claims): string
    {
        $header = $this->b64urlEncode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
            'nonce' => 'abc',
        ], \JSON_THROW_ON_ERROR));

        $payload = $this->b64urlEncode(json_encode($claims, \JSON_THROW_ON_ERROR));

        return $header.'.'.$payload.'.';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generateRsaKeypairAsJwk(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if (false === $key) {
            $this->fail('Failed to generate RSA keypair via OpenSSL.');
        }

        $privateKeyPem = '';
        if (!openssl_pkey_export($key, $privateKeyPem)) {
            $this->fail('Failed to export RSA private key.');
        }

        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            $this->fail('Failed to read RSA key details.');
        }

        $n = $this->b64urlEncode($details['rsa']['n']);
        $e = $this->b64urlEncode($details['rsa']['e']);

        $publicJwk = [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $n,
            'e' => $e,
        ];

        return [$privateKeyPem, $publicJwk];
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function createHttpClientMock(array $responses, ?int $expectedCalls = null): ClientInterface
    {
        $expectedCalls ??= \count($responses);

        $client = $this->createMock(ClientInterface::class);
        $client
            ->expects($this->exactly($expectedCalls))
            ->method('sendRequest')
            ->with($this->isInstanceOf(RequestInterface::class))
            ->willReturnCallback(static function () use (&$responses): ResponseInterface {
                if ([] === $responses) {
                    throw new \RuntimeException('No more mocked responses available.');
                }

                return array_shift($responses);
            });

        return $client;
    }
}
