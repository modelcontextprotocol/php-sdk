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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tests ProtectedResourceMetadata serialization and input validation.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class ProtectedResourceMetadataTest extends TestCase
{
    #[TestDox('serializes RFC 9728 metadata including human-readable fields')]
    public function testJsonSerializeIncludesHumanReadableFields(): void
    {
        $metadata = new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            scopesSupported: ['openid', 'profile'],
            resource: 'https://api.example.com/mcp',
            resourceName: 'Example MCP API',
            resourceDocumentation: 'https://api.example.com/docs',
            resourcePolicyUri: 'https://api.example.com/policy',
            resourceTosUri: 'https://api.example.com/tos',
            localizedHumanReadable: [
                'resource_name#en' => 'Example MCP API',
            ],
            extra: [
                'bearer_methods_supported' => ['header'],
            ],
            metadataPaths: ['.well-known/oauth-protected-resource'],
        );

        $this->assertSame(
            [
                'bearer_methods_supported' => ['header'],
                'authorization_servers' => ['https://auth.example.com'],
                'scopes_supported' => ['openid', 'profile'],
                'resource' => 'https://api.example.com/mcp',
                'resource_name' => 'Example MCP API',
                'resource_documentation' => 'https://api.example.com/docs',
                'resource_policy_uri' => 'https://api.example.com/policy',
                'resource_tos_uri' => 'https://api.example.com/tos',
                'resource_name#en' => 'Example MCP API',
            ],
            $metadata->jsonSerialize(),
        );
        $this->assertSame('/.well-known/oauth-protected-resource', $metadata->getPrimaryMetadataPath());
        $this->assertSame(['openid', 'profile'], $metadata->getScopesSupported());
    }

    #[TestDox('invalid localized human-readable field is rejected')]
    public function testInvalidLocalizedHumanReadableFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid localized human-readable field');

        new ProtectedResourceMetadata(
            authorizationServers: ['https://auth.example.com'],
            localizedHumanReadable: [
                'invalid#en' => 'value',
            ],
        );
    }

    #[TestDox('empty authorization servers are rejected')]
    public function testEmptyAuthorizationServersThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one authorization server');

        new ProtectedResourceMetadata([]);
    }
}
