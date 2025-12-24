<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Auth;

use Mcp\Server\Auth\JwtTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \Mcp\Server\Auth\JwtTokenAuthenticator
 */
final class JwtTokenAuthenticatorTest extends TestCase
{
    public function testRejectsMalformedJwt(): void
    {
        $authenticator = $this->createAuthenticator();

        // Not a JWT at all
        $result = $authenticator->authenticate('not-a-jwt');
        $this->assertFalse($result->authenticated);
        $this->assertSame('invalid_token', $result->error);

        // Only two parts
        $result = $authenticator->authenticate('part1.part2');
        $this->assertFalse($result->authenticated);

        // Four parts
        $result = $authenticator->authenticate('part1.part2.part3.part4');
        $this->assertFalse($result->authenticated);
    }

    public function testRejectsInvalidBase64Header(): void
    {
        $authenticator = $this->createAuthenticator();

        // Invalid base64 in header
        $result = $authenticator->authenticate('!!!.eyJ0ZXN0IjoxfQ.signature');
        $this->assertFalse($result->authenticated);
        $this->assertStringContainsString('header', $result->errorDescription ?? '');
    }

    public function testRejectsUnsupportedAlgorithm(): void
    {
        $authenticator = $this->createAuthenticator(['RS256']);

        // Create JWT with HS256 algorithm
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['sub' => 'user']));
        $token = str_replace(['+', '/', '='], ['-', '_', ''], "{$header}.{$payload}.signature");

        $result = $authenticator->authenticate($token);
        $this->assertFalse($result->authenticated);
        $this->assertSame('invalid_token', $result->error);
    }

    public function testRejectsInvalidPayload(): void
    {
        $authenticator = $this->createAuthenticator();

        // Valid header, invalid payload
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = '!!!invalid!!!';
        $token = "{$header}.{$payload}.signature";

        $result = $authenticator->authenticate($token);
        $this->assertFalse($result->authenticated);
    }

    public function testUnsupportedAlgorithmThrowsInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported algorithm');

        new JwtTokenAuthenticator(
            jwksUri: 'https://auth.example.com/.well-known/jwks.json',
            issuer: 'https://auth.example.com',
            algorithms: ['HS256'], // Not supported
        );
    }

    public function testSupportedAlgorithms(): void
    {
        // Should not throw
        $authenticator = new JwtTokenAuthenticator(
            jwksUri: 'https://auth.example.com/.well-known/jwks.json',
            issuer: 'https://auth.example.com',
            algorithms: ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'],
        );

        $this->assertInstanceOf(JwtTokenAuthenticator::class, $authenticator);
    }

    private function createAuthenticator(array $algorithms = ['RS256']): JwtTokenAuthenticator
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockRequestFactory = $this->createMock(RequestFactoryInterface::class);

        // Mock JWKS response
        $mockRequest = $this->createMock(RequestInterface::class);
        $mockRequest->method('withHeader')->willReturnSelf();

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn(json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => 'test-key',
                    'alg' => 'RS256',
                    'n' => 'test-modulus',
                    'e' => 'AQAB',
                ],
            ],
        ]));

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);

        $mockRequestFactory->method('createRequest')->willReturn($mockRequest);
        $mockClient->method('sendRequest')->willReturn($mockResponse);

        return new JwtTokenAuthenticator(
            jwksUri: 'https://auth.example.com/.well-known/jwks.json',
            issuer: 'https://auth.example.com',
            algorithms: $algorithms,
            httpClient: $mockClient,
            requestFactory: $mockRequestFactory,
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}

