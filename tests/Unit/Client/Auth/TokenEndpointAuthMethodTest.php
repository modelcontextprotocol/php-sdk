<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\TokenEndpointAuthMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class TokenEndpointAuthMethodTest extends TestCase
{
    /**
     * @return iterable<string, array{string[], bool, TokenEndpointAuthMethod}>
     */
    public static function negotiationProvider(): iterable
    {
        yield 'a public client can only be public' => [
            ['client_secret_basic', 'none'], false, TokenEndpointAuthMethod::None,
        ];

        yield 'a client with a secret prefers the header over the body' => [
            ['client_secret_post', 'client_secret_basic'], true, TokenEndpointAuthMethod::ClientSecretBasic,
        ];

        yield 'a server offering only the body form gets it' => [
            ['client_secret_post'], true, TokenEndpointAuthMethod::ClientSecretPost,
        ];

        yield 'a server that only takes public clients gets one' => [
            ['none'], true, TokenEndpointAuthMethod::None,
        ];

        // RFC 6749 makes Basic the one method every server must accept, so it is the
        // right guess for a server that documents nothing.
        yield 'a silent server is assumed to take Basic' => [
            [], true, TokenEndpointAuthMethod::ClientSecretBasic,
        ];

        yield 'a silent server with a public client still gets none' => [
            [], false, TokenEndpointAuthMethod::None,
        ];
    }

    #[DataProvider('negotiationProvider')]
    #[TestDox('the token endpoint method is negotiated for: $_dataName')]
    public function testNegotiate(array $supported, bool $hasSecret, TokenEndpointAuthMethod $expected): void
    {
        $this->assertSame($expected, TokenEndpointAuthMethod::negotiate($supported, $hasSecret));
    }
}
