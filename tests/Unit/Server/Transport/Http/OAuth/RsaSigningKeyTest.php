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

use Mcp\Server\Transport\Http\OAuth\RsaSigningKey;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class RsaSigningKeyTest extends TestCase
{
    #[TestDox('exposes the public key as an RS256 JWK with n, e and kid')]
    public function testPublicJwk(): void
    {
        $key = new RsaSigningKey($this->generatePem(), 'my-kid');

        $jwk = $key->getPublicJwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('my-kid', $jwk['kid']);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
        // base64url: no +, /, or = padding
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $jwk['n']);
    }

    #[TestDox('derives a stable key id when none is given')]
    public function testDerivesStableKeyId(): void
    {
        $pem = $this->generatePem();

        $this->assertSame((new RsaSigningKey($pem))->getKeyId(), (new RsaSigningKey($pem))->getKeyId());
    }

    private function generatePem(): string
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        return $pem;
    }
}
