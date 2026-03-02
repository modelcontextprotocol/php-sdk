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

use Mcp\Example\Server\OAuthMicrosoft\MicrosoftOidcMetadataPolicy;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tests MicrosoftOidcMetadataPolicy validation behavior.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class MicrosoftOidcMetadataPolicyTest extends TestCase
{
    #[TestDox('metadata without code challenge methods is accepted')]
    public function testMissingCodeChallengeMethodsIsAccepted(): void
    {
        $policy = new MicrosoftOidcMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
        ];

        $this->assertTrue($policy->isValid($metadata));
    }

    #[TestDox('malformed code challenge methods are ignored for validity')]
    public function testMalformedCodeChallengeMethodsSupportedIsAccepted(): void
    {
        $policy = new MicrosoftOidcMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => 'S256',
        ];

        $this->assertTrue($policy->isValid($metadata));
    }

    #[TestDox('required endpoint fields still enforce validity')]
    public function testIsValidRequiresCoreEndpoints(): void
    {
        $policy = new MicrosoftOidcMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            // token_endpoint missing
            'jwks_uri' => 'https://auth.example.com/jwks',
        ];

        $this->assertFalse($policy->isValid($metadata));
    }
}
