<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;

/**
 * An RSA signing key backed by a PEM-encoded private key, producing an RS256
 * public JWK via ext-openssl.
 */
final class RsaSigningKey implements SigningKeyInterface
{
    private const ALGORITHM = 'RS256';

    private string $keyId;

    /** @var array<string, mixed>|null */
    private ?array $publicJwk = null;

    public function __construct(
        private readonly string $privateKeyPem,
        ?string $keyId = null,
        private readonly ?string $passphrase = null,
    ) {
        if (!\function_exists('openssl_pkey_get_private')) {
            throw new RuntimeException('The RsaSigningKey requires the openssl extension (ext-openssl).');
        }

        if ('' === trim($privateKeyPem)) {
            throw new InvalidArgumentException('The private key PEM must not be empty.');
        }

        $this->keyId = $keyId ?? $this->deriveKeyId();
    }

    public static function fromFile(string $pemPath, ?string $keyId = null, ?string $passphrase = null): self
    {
        $contents = @file_get_contents($pemPath);
        if (false === $contents) {
            throw new InvalidArgumentException(\sprintf('Unable to read private key from "%s".', $pemPath));
        }

        return new self($contents, $keyId, $passphrase);
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function getPrivateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function getPublicJwk(): array
    {
        if (null !== $this->publicJwk) {
            return $this->publicJwk;
        }

        $details = $this->keyDetails();

        if (\OPENSSL_KEYTYPE_RSA !== ($details['type'] ?? null) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The signing key must be an RSA key.');
        }

        return $this->publicJwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALGORITHM,
            'kid' => $this->keyId,
            'n' => self::base64UrlEncode($details['rsa']['n']),
            'e' => self::base64UrlEncode($details['rsa']['e']),
        ];
    }

    /**
     * @return array{type?: int, rsa?: array{n?: string, e?: string}}
     */
    private function keyDetails(): array
    {
        $key = openssl_pkey_get_private($this->privateKeyPem, $this->passphrase ?? '');
        if (false === $key) {
            throw new RuntimeException('Unable to parse the private key: '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        if (false === $details) {
            throw new RuntimeException('Unable to read public key details: '.openssl_error_string());
        }

        /* @var array{type?: int, rsa?: array{n?: string, e?: string}} $details */
        return $details;
    }

    private function deriveKeyId(): string
    {
        $details = $this->keyDetails();
        $modulus = $details['rsa']['n'] ?? '';

        return substr(hash('sha256', $modulus), 0, 16);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
