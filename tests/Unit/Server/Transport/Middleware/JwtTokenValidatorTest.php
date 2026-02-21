<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Middleware;

use Firebase\JWT\JWT;
use Mcp\Server\Transport\Middleware\JwtTokenValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class JwtTokenValidatorTest extends TestCase
{
    #[TestDox('valid JWT is allowed and claims/scopes are exposed as request attributes')]
    public function testValidJwtAllowsAndExposesAttributes(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $jwksUri = 'https://auth.example.com/.well-known/jwks.json';
        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: $jwksUri,
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'client_id' => 'client-abc',
                'azp' => 'client-abc',
                'scope' => 'mcp:read mcp:write',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $result = $validator->validate($request, $token);

        $this->assertTrue($result->isAllowed());
        $attributes = $result->getAttributes();

        $this->assertArrayHasKey('oauth.claims', $attributes);
        $this->assertArrayHasKey('oauth.scopes', $attributes);
        $this->assertSame(['mcp:read', 'mcp:write'], $attributes['oauth.scopes']);
        $this->assertSame('user-123', $attributes['oauth.subject']);
        $this->assertSame('client-abc', $attributes['oauth.client_id']);
        $this->assertSame('client-abc', $attributes['oauth.authorized_party']);
    }

    #[TestDox('issuer mismatch yields unauthorized result')]
    public function testIssuerMismatchIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $jwksUri = 'https://auth.example.com/.well-known/jwks.json';
        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: $jwksUri,
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://other-issuer.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'scope' => 'mcp:read',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $result = $validator->validate($request, $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token issuer mismatch.', $result->getErrorDescription());
    }

    #[TestDox('audience mismatch yields unauthorized result')]
    public function testAudienceMismatchIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $jwksUri = 'https://auth.example.com/.well-known/jwks.json';
        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: ['mcp-api'],
            jwksUri: $jwksUri,
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'different-aud',
                'sub' => 'user-123',
                'scope' => 'mcp:read',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $result = $validator->validate($request, $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token audience mismatch.', $result->getErrorDescription());
    }

    #[TestDox('Graph token (nonce header) is validated by claims without signature verification')]
    public function testGraphTokenWithNonceHeaderIsAllowed(): void
    {
        $factory = new Psr17Factory();

        // Build a token with a header containing "nonce" to trigger validateGraphToken().
        $header = $this->b64urlEncode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
            'nonce' => 'abc',
        ], \JSON_THROW_ON_ERROR));

        $payload = $this->b64urlEncode(json_encode([
            'iss' => 'https://login.microsoftonline.com/tenant-id/v2.0',
            'aud' => 'mcp-api',
            'sub' => 'user-graph',
            'scp' => 'files.read files.write',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ], \JSON_THROW_ON_ERROR));

        $token = $header.'.'.$payload.'.';

        $validator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksUri: 'https://unused.example.com/jwks',
            httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
            requestFactory: $factory,
            scopeClaim: 'scp',
        );

        $request = $factory->createServerRequest('GET', 'https://mcp.example.com/mcp');
        $result = $validator->validate($request, $token);

        $this->assertTrue($result->isAllowed());
        $attributes = $result->getAttributes();

        $this->assertTrue($attributes['oauth.graph_token']);
        $this->assertSame(['files.read', 'files.write'], $attributes['oauth.scopes']);
        $this->assertSame('user-graph', $attributes['oauth.subject']);
    }

    #[TestDox('expired token yields unauthorized invalid_token with expired message')]
    public function testExpiredTokenIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'iat' => time() - 7200,
                'exp' => time() - 10,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token has expired.', $result->getErrorDescription());
    }

    #[TestDox('token with future nbf yields unauthorized invalid_token with not-yet-valid message')]
    public function testBeforeValidTokenIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'iat' => time(),
                'nbf' => time() + 3600,
                'exp' => time() + 7200,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token is not yet valid.', $result->getErrorDescription());
    }

    #[TestDox('signature verification failure yields unauthorized invalid_token with signature message')]
    public function testSignatureInvalidIsUnauthorized(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        // Create a mismatched JWK with the same kid so the key lookup succeeds but signature verification fails.
        [, $mismatchedJwk] = $this->generateRsaKeypairAsJwk('test-kid');
        $mismatchedJwk['kid'] = $publicJwk['kid'];

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$mismatchedJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token signature verification failed.', $result->getErrorDescription());
    }

    #[TestDox('JWKS HTTP error results in unauthorized token validation error')]
    public function testJwksHttpErrorResultsInUnauthorized(): void
    {
        $factory = new Psr17Factory();

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $this->createHttpClientMock([$factory->createResponse(500)]),
            requestFactory: $factory,
        );

        // Any token without the Graph nonce will attempt JWKS and fail.
        $token = $this->unsignedJwt(['iss' => 'https://auth.example.com', 'aud' => 'mcp-api']);

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token validation error.', $result->getErrorDescription());
    }

    #[TestDox('Invalid JWKS JSON results in unauthorized token validation error')]
    public function testInvalidJwksJsonResultsInUnauthorized(): void
    {
        $factory = new Psr17Factory();

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream('{not-json')),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = $this->unsignedJwt(['iss' => 'https://auth.example.com', 'aud' => 'mcp-api']);

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token validation error.', $result->getErrorDescription());
    }

    #[TestDox('JWKS without keys array results in unauthorized token validation error')]
    public function testJwksMissingKeysResultsInUnauthorized(): void
    {
        $factory = new Psr17Factory();

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['nope' => []], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = $this->unsignedJwt(['iss' => 'https://auth.example.com', 'aud' => 'mcp-api']);

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Token validation error.', $result->getErrorDescription());
    }

    #[TestDox('requireScopes returns forbidden when any required scope is missing')]
    public function testRequireScopesForbiddenWhenMissing(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'scope' => 'mcp:read',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);
        $this->assertTrue($result->isAllowed());

        $scoped = $validator->requireScopes($result, ['mcp:read', 'mcp:write']);
        $this->assertFalse($scoped->isAllowed());
        $this->assertSame(403, $scoped->getStatusCode());
        $this->assertSame('insufficient_scope', $scoped->getError());
        $this->assertSame(['mcp:read', 'mcp:write'], $scoped->getScopes());
    }

    #[TestDox('requireScopes passes through when all required scopes are present')]
    public function testRequireScopesPassesWhenPresent(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $httpClient = $this->createHttpClientMock([
            $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR))),
        ]);

        $validator = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $token = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'scope' => ['mcp:read', 'mcp:write'],
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);
        $this->assertTrue($result->isAllowed());

        $scoped = $validator->requireScopes($result, ['mcp:read']);
        $this->assertTrue($scoped->isAllowed());
    }

    #[TestDox('Graph token invalid format is unauthorized')]
    public function testGraphTokenInvalidFormatIsUnauthorized(): void
    {
        $factory = new Psr17Factory();

        $header = $this->b64urlEncode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
            'nonce' => 'abc',
        ], \JSON_THROW_ON_ERROR));

        // Trigger the Graph token path (nonce in header) with an empty payload segment.
        // This makes validateGraphToken() run and fail decoding the payload.
        $token = $header.'..';

        $validator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksUri: 'https://unused.example.com/jwks',
            httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
            requestFactory: $factory,
            scopeClaim: 'scp',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Invalid token payload.', $result->getErrorDescription());
    }

    #[TestDox('Graph token invalid issuer is unauthorized with graph issuer message')]
    public function testGraphTokenInvalidIssuerIsUnauthorized(): void
    {
        $factory = new Psr17Factory();

        $header = $this->b64urlEncode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
            'nonce' => 'abc',
        ], \JSON_THROW_ON_ERROR));

        $payload = $this->b64urlEncode(json_encode([
            'iss' => 'https://evil.example.com',
            'aud' => 'mcp-api',
            'sub' => 'user-graph',
            'scp' => 'files.read',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ], \JSON_THROW_ON_ERROR));

        $token = $header.'.'.$payload.'.';

        $validator = new JwtTokenValidator(
            issuer: ['https://auth.example.com'],
            audience: ['mcp-api'],
            jwksUri: 'https://unused.example.com/jwks',
            httpClient: $this->createHttpClientMock([$factory->createResponse(500)], 0),
            requestFactory: $factory,
            scopeClaim: 'scp',
        );

        $result = $validator->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertSame('Invalid token issuer for Graph token.', $result->getErrorDescription());
    }

    #[TestDox('extractScopes returns empty array when scope claim is missing or invalid type')]
    public function testExtractScopesEdgeCases(): void
    {
        $factory = new Psr17Factory();
        [$privateKeyPem, $publicJwk] = $this->generateRsaKeypairAsJwk('test-kid');

        $jwksResponse = $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['keys' => [$publicJwk]], \JSON_THROW_ON_ERROR)));

        $httpClient = $this->createHttpClientMock([$jwksResponse]);

        // missing scope
        $validatorMissing = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $tokenMissing = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $resultMissing = $validatorMissing->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $tokenMissing);
        $this->assertTrue($resultMissing->isAllowed());
        $this->assertSame([], $resultMissing->getAttributes()['oauth.scopes']);

        // invalid scope type
        $httpClient2 = $this->createHttpClientMock([$jwksResponse]);

        $validatorInvalid = new JwtTokenValidator(
            issuer: 'https://auth.example.com',
            audience: 'mcp-api',
            jwksUri: 'https://auth.example.com/jwks',
            httpClient: $httpClient2,
            requestFactory: $factory,
        );

        $tokenInvalid = JWT::encode(
            [
                'iss' => 'https://auth.example.com',
                'aud' => 'mcp-api',
                'sub' => 'user-123',
                'scope' => 123,
                'iat' => time() - 10,
                'exp' => time() + 600,
            ],
            $privateKeyPem,
            'RS256',
            keyId: 'test-kid',
        );

        $resultInvalid = $validatorInvalid->validate($factory->createServerRequest('GET', 'https://mcp.example.com/mcp'), $tokenInvalid);
        $this->assertTrue($resultInvalid->isAllowed());
        $this->assertSame([], $resultInvalid->getAttributes()['oauth.scopes']);
    }

    private function unsignedJwt(array $claims): string
    {
        $header = $this->b64urlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR));
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

        $httpClient = $this->createMock(ClientInterface::class);
        $expectation = $httpClient
            ->expects($this->exactly($expectedCalls))
            ->method('sendRequest')
            ->with($this->isInstanceOf(RequestInterface::class));

        if (1 === $expectedCalls) {
            $expectation->willReturn($responses[0]);
        } else {
            // If expectedCalls > count(responses), keep returning the last response.
            $sequence = $responses;
            while (\count($sequence) < $expectedCalls) {
                $sequence[] = $responses[array_key_last($responses)];
            }
            $expectation->willReturnOnConsecutiveCalls(...$sequence);
        }

        return $httpClient;
    }
}
