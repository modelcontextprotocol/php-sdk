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

use Mcp\Server\Auth\AuthenticationResult;
use Mcp\Server\Auth\OAuth2Configuration;
use Mcp\Server\Auth\ProtectedResourceMetadata;
use Mcp\Server\Auth\TokenAuthenticatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mcp\Server\Auth\OAuth2Configuration
 */
final class OAuth2ConfigurationTest extends TestCase
{
    private TokenAuthenticatorInterface $mockAuthenticator;
    private ProtectedResourceMetadata $metadata;

    protected function setUp(): void
    {
        $this->mockAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $this->metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com',
            authorizationServers: ['https://auth.example.com'],
        );
    }

    public function testDefaultResourceMetadataPath(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
        );

        $this->assertSame('/.well-known/oauth-protected-resource', $config->getResourceMetadataPath());
    }

    public function testCustomResourceMetadataPath(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
            resourceMetadataPath: '/custom/metadata',
        );

        $this->assertSame('/custom/metadata', $config->getResourceMetadataPath());
    }

    public function testResourceMetadataUrl(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
        );

        $this->assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            $config->getResourceMetadataUrl()
        );
    }

    public function testResourceMetadataUrlWithPath(): void
    {
        $metadata = new ProtectedResourceMetadata(
            resource: 'https://mcp.example.com/api/v1',
            authorizationServers: ['https://auth.example.com'],
        );

        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $metadata,
        );

        $url = $config->getResourceMetadataUrl();
        $this->assertStringContainsString('/.well-known/oauth-protected-resource', $url);
    }

    public function testIsPublicPathForMetadataEndpoint(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
        );

        $this->assertTrue($config->isPublicPath('/.well-known/oauth-protected-resource'));
        $this->assertTrue($config->isPublicPath('/.well-known/oauth-protected-resource/some/path'));
    }

    public function testIsPublicPathForCustomPaths(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
            publicPaths: ['/health', '/api/public/*'],
        );

        $this->assertTrue($config->isPublicPath('/health'));
        $this->assertTrue($config->isPublicPath('/api/public/status'));
        $this->assertTrue($config->isPublicPath('/api/public/anything'));
        $this->assertFalse($config->isPublicPath('/api/private'));
        $this->assertFalse($config->isPublicPath('/'));
    }

    public function testProtectedPathsRequireAuth(): void
    {
        $config = new OAuth2Configuration(
            tokenAuthenticator: $this->mockAuthenticator,
            resourceMetadata: $this->metadata,
        );

        $this->assertFalse($config->isPublicPath('/'));
        $this->assertFalse($config->isPublicPath('/mcp'));
        $this->assertFalse($config->isPublicPath('/api/tools'));
    }
}

