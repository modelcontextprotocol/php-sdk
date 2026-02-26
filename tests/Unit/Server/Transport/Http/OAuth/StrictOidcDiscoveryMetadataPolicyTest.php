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

use Mcp\Server\Transport\Http\OAuth\StrictOidcDiscoveryMetadataPolicy;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tests StrictOidcDiscoveryMetadataPolicy validation behavior.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class StrictOidcDiscoveryMetadataPolicyTest extends TestCase
{
    #[TestDox('metadata without code challenge methods is invalid in strict mode')]
    public function testMissingCodeChallengeMethodsIsInvalid(): void
    {
        $policy = new StrictOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
        ];

        $this->assertFalse($policy->isValid($metadata));
    }

    #[TestDox('valid code challenge methods list is accepted in strict mode')]
    public function testValidCodeChallengeMethodsIsAccepted(): void
    {
        $policy = new StrictOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $this->assertTrue($policy->isValid($metadata));
    }

    #[TestDox('empty code challenge methods list is invalid in strict mode')]
    public function testEmptyCodeChallengeMethodsIsInvalid(): void
    {
        $policy = new StrictOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => [],
        ];

        $this->assertFalse($policy->isValid($metadata));
    }

    #[TestDox('non string code challenge method is invalid in strict mode')]
    public function testNonStringCodeChallengeMethodIsInvalid(): void
    {
        $policy = new StrictOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => ['S256', 123],
        ];

        $this->assertFalse($policy->isValid($metadata));
    }
}
