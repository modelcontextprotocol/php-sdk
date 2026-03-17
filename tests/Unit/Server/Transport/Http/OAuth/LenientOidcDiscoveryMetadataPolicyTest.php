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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class LenientOidcDiscoveryMetadataPolicyTest extends TestCase
{
    #[DataProvider('provideValidMetadata')]
    #[TestDox('valid metadata: $description')]
    public function testValidMetadata(mixed $metadata, string $description): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();

        $this->assertTrue($policy->isValid($metadata));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideValidMetadata(): iterable
    {
        $base = [
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/jwks',
        ];

        yield 'without code_challenge_methods_supported' => [$base, 'without code_challenge_methods_supported'];

        yield 'with code_challenge_methods_supported' => [
            $base + ['code_challenge_methods_supported' => ['S256']],
            'with code_challenge_methods_supported',
        ];
    }

    #[DataProvider('provideInvalidMetadata')]
    #[TestDox('invalid metadata: $description')]
    public function testInvalidMetadata(mixed $metadata, string $description): void
    {
        $policy = new LenientOidcDiscoveryMetadataPolicy();

        $this->assertFalse($policy->isValid($metadata));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideInvalidMetadata(): iterable
    {
        yield 'missing authorization_endpoint' => [
            [
                'token_endpoint' => 'https://auth.example.com/token',
                'jwks_uri' => 'https://auth.example.com/jwks',
            ],
            'missing authorization_endpoint',
        ];

        yield 'missing token_endpoint' => [
            [
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'jwks_uri' => 'https://auth.example.com/jwks',
            ],
            'missing token_endpoint',
        ];

        yield 'missing jwks_uri' => [
            [
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
            ],
            'missing jwks_uri',
        ];

        yield 'empty endpoint string' => [
            [
                'authorization_endpoint' => '',
                'token_endpoint' => 'https://auth.example.com/token',
                'jwks_uri' => 'https://auth.example.com/jwks',
            ],
            'empty endpoint string',
        ];

        yield 'non-array metadata' => ['not an array', 'non-array metadata'];
    }
}
