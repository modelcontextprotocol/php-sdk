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
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\OidcDiscoveryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Tests JwksProvider loading, validation, and caching behavior.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class JwksProviderTest extends TestCase
{
    #[TestDox('JWKS are loaded from explicit URI')]
    public function testGetJwksFromExplicitUri(): void
    {
        $factory = new Psr17Factory();
        $jwksUri = 'https://auth.example.com/jwks';
        $jwks = [
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'kid-1', 'n' => 'abc', 'e' => 'AQAB'],
            ],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($jwks, \JSON_THROW_ON_ERROR)),
                ),
            );

        $provider = new JwksProvider(
            discovery: $this->createDiscoveryStub(),
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $result = $provider->getJwks('https://auth.example.com', $jwksUri);

        $this->assertSame($jwks, $result);
    }

    #[TestDox('invalid cached JWKS are ignored and replaced by fetched values')]
    public function testInvalidCachedJwksAreIgnored(): void
    {
        $factory = new Psr17Factory();
        $jwksUri = 'https://auth.example.com/jwks';
        $jwks = [
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'kid-1', 'n' => 'abc', 'e' => 'AQAB'],
            ],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($jwks, \JSON_THROW_ON_ERROR)),
                ),
            );

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(['keys' => []]);
        $cache->expects($this->once())
            ->method('set');

        $provider = new JwksProvider(
            discovery: $this->createDiscoveryStub(),
            httpClient: $httpClient,
            requestFactory: $factory,
            cache: $cache,
        );

        $result = $provider->getJwks('https://auth.example.com', $jwksUri);

        $this->assertSame($jwks, $result);
    }

    #[TestDox('discovery is used when explicit JWKS URI is not provided')]
    public function testDiscoveryIsUsedWhenUriIsMissing(): void
    {
        $factory = new Psr17Factory();
        $jwksUri = 'https://auth.example.com/jwks';
        $jwks = [
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'kid-1', 'n' => 'abc', 'e' => 'AQAB'],
            ],
        ];

        $discovery = $this->createMock(OidcDiscoveryInterface::class);
        $discovery->expects($this->once())
            ->method('getJwksUri')
            ->with('https://auth.example.com')
            ->willReturn($jwksUri);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode($jwks, \JSON_THROW_ON_ERROR)),
                ),
            );

        $provider = new JwksProvider(
            httpClient: $httpClient,
            requestFactory: $factory,
            discovery: $discovery,
        );

        $result = $provider->getJwks('https://auth.example.com');

        $this->assertSame($jwks, $result);
    }

    #[TestDox('empty keys in fetched JWKS throw RuntimeException')]
    public function testEmptyKeysThrow(): void
    {
        $factory = new Psr17Factory();
        $jwksUri = 'https://auth.example.com/jwks';

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['keys' => []], \JSON_THROW_ON_ERROR)),
                ),
            );

        $provider = new JwksProvider(
            discovery: $this->createDiscoveryStub(),
            httpClient: $httpClient,
            requestFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected non-empty "keys" array');

        $provider->getJwks('https://auth.example.com', $jwksUri);
    }

    private function createDiscoveryStub(): OidcDiscoveryInterface
    {
        return $this->createStub(OidcDiscoveryInterface::class);
    }
}
