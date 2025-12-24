<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Auth;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * JWT Token Authenticator using JWKS.
 *
 * Validates JWT access tokens by fetching public keys from a JWKS endpoint.
 * Supports RS256, RS384, RS512, ES256, ES384, ES512 algorithms.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class JwtTokenAuthenticator implements TokenAuthenticatorInterface
{
    private const SUPPORTED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];

    /** @var array<string, mixed>|null */
    private ?array $jwksCache = null;
    private ?int $jwksCacheTime = null;

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    /**
     * @param string $jwksUri URI to fetch JWKS from
     * @param string $issuer Expected issuer claim
     * @param string|null $audience Expected audience claim (MCP server canonical URI)
     * @param string[] $algorithms Allowed signing algorithms
     * @param int $leeway Clock skew tolerance in seconds
     * @param int $jwksCacheTtl How long to cache JWKS in seconds
     * @param ClientInterface|null $httpClient PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     */
    public function __construct(
        private readonly string $jwksUri,
        private readonly string $issuer,
        private readonly ?string $audience = null,
        private readonly array $algorithms = ['RS256'],
        private readonly int $leeway = 60,
        private readonly int $jwksCacheTtl = 3600,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        foreach ($algorithms as $alg) {
            if (!in_array($alg, self::SUPPORTED_ALGORITHMS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported algorithm "%s". Supported: %s',
                    $alg,
                    implode(', ', self::SUPPORTED_ALGORITHMS)
                ));
            }
        }

        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    public function authenticate(string $token, ?string $resource = null): AuthenticationResult
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Malformed JWT');
            }

            // Decode header to get kid
            $headerJson = $this->base64UrlDecode($parts[0]);
            if (false === $headerJson) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid JWT header encoding');
            }

            $header = json_decode($headerJson, true);
            if (!is_array($header) || !isset($header['alg'])) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid JWT header');
            }

            if (!in_array($header['alg'], $this->algorithms, true)) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Unsupported algorithm');
            }

            // Decode payload
            $payloadJson = $this->base64UrlDecode($parts[1]);
            if (false === $payloadJson) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid JWT payload encoding');
            }

            $payload = json_decode($payloadJson, true);
            if (!is_array($payload)) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid JWT payload');
            }

            // Verify signature
            $signature = $this->base64UrlDecode($parts[2]);
            if (false === $signature) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid JWT signature encoding');
            }

            $kid = $header['kid'] ?? null;
            $key = $this->getPublicKey($kid, $header['alg']);
            if (null === $key) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Unable to verify signature');
            }

            $dataToVerify = $parts[0] . '.' . $parts[1];
            if (!$this->verifySignature($dataToVerify, $signature, $key, $header['alg'])) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid signature');
            }

            // Validate claims
            $now = $this->clock->now()->getTimestamp();

            // Check expiration
            if (isset($payload['exp']) && is_numeric($payload['exp'])) {
                if ($now > ((int)$payload['exp'] + $this->leeway)) {
                    return AuthenticationResult::unauthenticated('invalid_token', 'Token has expired');
                }
            }

            // Check not before
            if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
                if ($now < ((int)$payload['nbf'] - $this->leeway)) {
                    return AuthenticationResult::unauthenticated('invalid_token', 'Token not yet valid');
                }
            }

            // Check issued at
            if (isset($payload['iat']) && is_numeric($payload['iat'])) {
                if ($now < ((int)$payload['iat'] - $this->leeway)) {
                    return AuthenticationResult::unauthenticated('invalid_token', 'Token issued in the future');
                }
            }

            // Verify issuer
            if (!isset($payload['iss']) || $payload['iss'] !== $this->issuer) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Invalid issuer');
            }

            // Verify audience (MCP server canonical URI)
            $expectedAudience = $resource ?? $this->audience;
            if (null !== $expectedAudience) {
                $aud = $payload['aud'] ?? null;
                $audList = is_array($aud) ? $aud : [$aud];
                if (!in_array($expectedAudience, $audList, true)) {
                    $this->logger->warning(
                        'Token audience mismatch',
                        [
                            'expected' => $expectedAudience,
                            'actual' => $aud,
                        ]
                    );

                    return AuthenticationResult::unauthenticated('invalid_token', 'Token not intended for this resource');
                }
            }

            return AuthenticationResult::authenticated($payload, ['token_type' => 'jwt']);
        } catch (\Throwable $e) {
            $this->logger->error('JWT authentication failed', ['exception' => $e]);

            return AuthenticationResult::unauthenticated('invalid_token', 'Token validation failed');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPublicKey(?string $kid, string $alg): ?array
    {
        $jwks = $this->fetchJwks();
        if (null === $jwks || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }

        foreach ($jwks['keys'] as $key) {
            if (!is_array($key)) {
                continue;
            }

            // If kid is specified, match it
            if (null !== $kid && isset($key['kid']) && $key['kid'] !== $kid) {
                continue;
            }

            // Check algorithm compatibility
            if (isset($key['alg']) && $key['alg'] !== $alg) {
                continue;
            }

            // Check key type matches algorithm
            $kty = $key['kty'] ?? null;
            if (str_starts_with($alg, 'RS') && $kty !== 'RSA') {
                continue;
            }
            if (str_starts_with($alg, 'ES') && $kty !== 'EC') {
                continue;
            }

            // Check key use
            $use = $key['use'] ?? 'sig';
            if ($use !== 'sig') {
                continue;
            }

            return $key;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJwks(): ?array
    {
        // Check cache
        if (null !== $this->jwksCache && null !== $this->jwksCacheTime) {
            if (time() - $this->jwksCacheTime < $this->jwksCacheTtl) {
                return $this->jwksCache;
            }
        }

        try {
            $request = $this->requestFactory->createRequest('GET', $this->jwksUri)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to fetch JWKS', [
                    'uri' => $this->jwksUri,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            $body = (string)$response->getBody();
            $jwks = json_decode($body, true);

            if (!is_array($jwks)) {
                $this->logger->error('Invalid JWKS response', ['uri' => $this->jwksUri]);

                return null;
            }

            $this->jwksCache = $jwks;
            $this->jwksCacheTime = time();

            return $jwks;
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching JWKS', ['exception' => $e, 'uri' => $this->jwksUri]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function verifySignature(string $data, string $signature, array $jwk, string $alg): bool
    {
        $publicKey = $this->jwkToPem($jwk);
        if (null === $publicKey) {
            return false;
        }

        $algorithm = match ($alg) {
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
            'ES256' => OPENSSL_ALGO_SHA256,
            'ES384' => OPENSSL_ALGO_SHA384,
            'ES512' => OPENSSL_ALGO_SHA512,
            default => null,
        };

        if (null === $algorithm) {
            return false;
        }

        // For ECDSA, convert signature from JWT format to DER
        if (str_starts_with($alg, 'ES')) {
            $signature = $this->convertEcdsaSignatureToDer($signature, $alg);
            if (null === $signature) {
                return false;
            }
        }

        return 1 === openssl_verify($data, $signature, $publicKey, $algorithm);
    }

    /**
     * Convert JWK to PEM format.
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkToPem(array $jwk): ?string
    {
        $kty = $jwk['kty'] ?? null;

        if ('RSA' === $kty) {
            return $this->rsaJwkToPem($jwk);
        }

        if ('EC' === $kty) {
            return $this->ecJwkToPem($jwk);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function rsaJwkToPem(array $jwk): ?string
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        if (false === $n || false === $e) {
            return null;
        }

        // Build RSA public key in DER format
        $modulus = "\x00" . $n; // Prepend 0x00 to ensure positive integer
        $exponent = $e;

        $modulusLen = strlen($modulus);
        $exponentLen = strlen($exponent);

        // Sequence of INTEGER (modulus) and INTEGER (exponent)
        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus) .
            $this->asn1Integer($exponent)
        );

        // RSA algorithm identifier
        $algorithmIdentifier = $this->asn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . // OID for RSA
            "\x05\x00" // NULL
        );

        // SubjectPublicKeyInfo
        $publicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier .
            $this->asn1BitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($publicKeyInfo), 64, "\n") .
            "-----END PUBLIC KEY-----";
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function ecJwkToPem(array $jwk): ?string
    {
        if (!isset($jwk['x'], $jwk['y'], $jwk['crv'])) {
            return null;
        }

        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);

        if (false === $x || false === $y) {
            return null;
        }

        // OID for the curve
        $curveOid = match ($jwk['crv']) {
            'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",
            'P-521' => "\x06\x05\x2b\x81\x04\x00\x23",
            default => null,
        };

        if (null === $curveOid) {
            return null;
        }

        // Pad coordinates to curve size
        $coordinateSize = match ($jwk['crv']) {
            'P-256' => 32,
            'P-384' => 48,
            'P-521' => 66,
            default => 0,
        };

        $x = str_pad($x, $coordinateSize, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, $coordinateSize, "\x00", STR_PAD_LEFT);

        // Uncompressed point format: 0x04 || x || y
        $publicKeyData = "\x04" . $x . $y;

        // Algorithm identifier for EC
        $algorithmIdentifier = $this->asn1Sequence(
            "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . // OID for EC public key
            $curveOid
        );

        // SubjectPublicKeyInfo
        $publicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier .
            $this->asn1BitString($publicKeyData)
        );

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($publicKeyInfo), 64, "\n") .
            "-----END PUBLIC KEY-----";
    }

    private function convertEcdsaSignatureToDer(string $signature, string $alg): ?string
    {
        $componentSize = match ($alg) {
            'ES256' => 32,
            'ES384' => 48,
            'ES512' => 66,
            default => 0,
        };

        if (strlen($signature) !== 2 * $componentSize) {
            return null;
        }

        $r = substr($signature, 0, $componentSize);
        $s = substr($signature, $componentSize);

        // Remove leading zeros but keep at least one byte
        $r = ltrim($r, "\x00") ?: "\x00";
        $s = ltrim($s, "\x00") ?: "\x00";

        // Add leading zero if high bit is set (to keep positive)
        if (ord($r[0]) & 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) & 0x80) {
            $s = "\x00" . $s;
        }

        return $this->asn1Sequence(
            $this->asn1Integer($r) .
            $this->asn1Integer($s)
        );
    }

    private function asn1Sequence(string $content): string
    {
        return "\x30" . $this->asn1Length(strlen($content)) . $content;
    }

    private function asn1Integer(string $content): string
    {
        return "\x02" . $this->asn1Length(strlen($content)) . $content;
    }

    private function asn1BitString(string $content): string
    {
        return "\x03" . $this->asn1Length(strlen($content) + 1) . "\x00" . $content;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        if ($length < 256) {
            return "\x81" . chr($length);
        }
        if ($length < 65536) {
            return "\x82" . chr($length >> 8) . chr($length & 0xff);
        }

        throw new \RuntimeException('ASN.1 length too long');
    }

    private function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'));
    }
}

