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

use Mcp\Exception\RuntimeException;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Tests OidcDiscovery metadata resolution, validation, and caching behavior.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class OidcDiscoveryTest extends TestCase
{
    #[TestDox('invalid issuer URL throws RuntimeException')]
    public function testInvalidIssuerUrlThrows(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $discovery = new OidcDiscovery(
            httpClient: $this->createMock(ClientInterface::class),
            requestFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid issuer URL');
        $discovery->discover('invalid-issuer');
    }

    #[TestDox('strict discovery rejects metadata without code challenge methods')]
    public function testDiscoverRejectsMetadataWithoutCodeChallengeMethodsSupported(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $issuer = 'https://auth.example.com';
        $metadataWithoutCodeChallengeMethods = [
            'issuer' => $issuer,
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn($factory->createResponse(200)->withBody(
                $factory->createStream(json_encode($metadataWithoutCodeChallengeMethods, \JSON_THROW_ON_ERROR)),
            ));

        $discovery = new OidcDiscovery(
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to discover authorization server metadata');
        $discovery->discover($issuer);
    }

    #[TestDox('discover falls back to the next metadata URL when first response is invalid')]
    public function testDiscoverFallsBackOnInvalidMetadataResponse(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $requestedUrls = [];

        $invalidMetadata = [
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            // token_endpoint is intentionally missing
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
        ];
        $validMetadata = [
            'issuer' => 'https://auth.example.com/tenant',
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function (RequestInterface $request) use ($factory, &$requestedUrls, $invalidMetadata, $validMetadata): ResponseInterface {
                $requestedUrls[] = (string) $request->getUri();

                $payload = 1 === \count($requestedUrls) ? $invalidMetadata : $validMetadata;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)),
                );
            });

        $discovery = new OidcDiscovery(
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $metadata = $discovery->discover('https://auth.example.com/tenant');

        $this->assertSame($validMetadata['authorization_endpoint'], $metadata['authorization_endpoint']);
        $this->assertSame($validMetadata['token_endpoint'], $metadata['token_endpoint']);
        $this->assertSame($validMetadata['jwks_uri'], $metadata['jwks_uri']);
        $this->assertSame(
            'https://auth.example.com/.well-known/oauth-authorization-server/tenant',
            $requestedUrls[0],
        );
        $this->assertSame(
            'https://auth.example.com/.well-known/openid-configuration/tenant',
            $requestedUrls[1],
        );
    }

    #[TestDox('valid metadata from cache is returned without HTTP call')]
    public function testDiscoverUsesValidCacheWithoutHttpCall(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $cachedMetadata = [
            'issuer' => 'https://auth.example.com/tenant',
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedMetadata);
        $cache->expects($this->never())->method('set');

        $discovery = new OidcDiscovery(
            httpClient: $httpClient,
            requestFactory: $factory,
            cache: $cache,
        );

        $metadata = $discovery->discover('https://auth.example.com/tenant');

        $this->assertSame($cachedMetadata, $metadata);
    }

    #[TestDox('discover skips metadata when issuer claim does not match requested issuer')]
    public function testDiscoverSkipsIssuerMismatch(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $requestedUrls = [];

        $issuerMismatch = [
            'issuer' => 'https://auth.example.com/other-tenant',
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
            'code_challenge_methods_supported' => ['S256'],
        ];
        $validMetadata = [
            'issuer' => 'https://auth.example.com/tenant',
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function (RequestInterface $request) use ($factory, &$requestedUrls, $issuerMismatch, $validMetadata): ResponseInterface {
                $requestedUrls[] = (string) $request->getUri();

                $payload = 1 === \count($requestedUrls) ? $issuerMismatch : $validMetadata;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)),
                );
            });

        $discovery = new OidcDiscovery(
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $metadata = $discovery->discover('https://auth.example.com/tenant');

        $this->assertSame($validMetadata['issuer'], $metadata['issuer']);
        $this->assertSame(
            'https://auth.example.com/.well-known/oauth-authorization-server/tenant',
            $requestedUrls[0],
        );
        $this->assertSame(
            'https://auth.example.com/.well-known/openid-configuration/tenant',
            $requestedUrls[1],
        );
    }

    #[TestDox('issuer without path uses standard well-known endpoints')]
    public function testIssuerWithoutPathUsesStandardWellKnownEndpoints(): void
    {
        $this->skipIfPsrHttpClientIsMissing();

        $factory = new Psr17Factory();
        $requestedUrls = [];
        $validMetadata = [
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://auth.example.com/oauth2/v2.0/token',
            'jwks_uri' => 'https://auth.example.com/discovery/v2.0/keys',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function (RequestInterface $request) use ($factory, &$requestedUrls, $validMetadata): ResponseInterface {
                $requestedUrls[] = (string) $request->getUri();

                if (1 === \count($requestedUrls)) {
                    return $factory->createResponse(404);
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($validMetadata, \JSON_THROW_ON_ERROR)),
                );
            });

        $discovery = new OidcDiscovery(
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $metadata = $discovery->discover('https://auth.example.com');

        $this->assertSame($validMetadata['jwks_uri'], $metadata['jwks_uri']);
        $this->assertSame('https://auth.example.com/.well-known/oauth-authorization-server', $requestedUrls[0]);
        $this->assertSame('https://auth.example.com/.well-known/openid-configuration', $requestedUrls[1]);
    }

    private function skipIfPsrHttpClientIsMissing(): void
    {
        if (!interface_exists(ClientInterface::class)) {
            $this->markTestSkipped('psr/http-client is not available in this runtime.');
        }
    }
}
