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

use Mcp\Server\Transport\Http\OAuth\LenientOidcDiscoveryMetadataPolicy;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tests LenientOidcDiscoveryMetadataPolicy validation behavior.
 *
 * @author Simon Chrzanowski <simon.chrzanowski@quentic.com>
 */
class LenientOidcDiscoveryMetadataPolicyTest extends TestCase
{
    #[TestDox('metadata without code challenge methods is valid (defaults to S256 downstream)')]
    public function testMissingCodeChallengeMethodsIsValid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
        ];

        $this->assertTrue($policy->isValid($metadata));
    }

    #[TestDox('valid code challenge methods list is accepted')]
    public function testValidCodeChallengeMethodsIsAccepted(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => ['S256'],
        ];

        $this->assertTrue($policy->isValid($metadata));
    }

    #[TestDox('empty code challenge methods list is invalid')]
    public function testEmptyCodeChallengeMethodsIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => [],
        ];

        $this->assertFalse($policy->isValid($metadata));
    }

    #[TestDox('non string code challenge method is invalid')]
    public function testNonStringCodeChallengeMethodIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => ['S256', 123],
        ];

        $this->assertFalse($policy->isValid($metadata));
    }

    #[TestDox('missing required fields is invalid')]
    public function testMissingRequiredFieldsIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();

        $this->assertFalse($policy->isValid([
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            // missing jwks_uri
        ]));
    }

    #[TestDox('empty string endpoint is invalid')]
    public function testEmptyStringEndpointIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();

        $this->assertFalse($policy->isValid([
            'authorization_endpoint' => '',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
        ]));
    }

    #[TestDox('null code challenge methods is invalid')]
    public function testNullCodeChallengeMethodsIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();
        $metadata = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
            'code_challenge_methods_supported' => null,
        ];

        $this->assertFalse($policy->isValid($metadata));
    }

    #[TestDox('non-array input is invalid')]
    public function testNonArrayInputIsInvalid(): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();

        $this->assertFalse($policy->isValid('not an array'));
    }
}
