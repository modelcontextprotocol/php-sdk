<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestStateException;

/**
 * Seals and verifies the opaque `requestState` carried across the rounds of a
 * multi round-trip request (SEP-2322).
 *
 * The value is `base64url(payload).base64url(HMAC)`. It passes through the
 * client, so it is attacker-controlled on return and
 * {@see self::verify()} refuses anything whose MAC or expiry does not hold.
 * Signed, not encrypted: nothing secret belongs in the payload.
 *
 * Takes one key or a key ring to rotate without failing the calls in flight:
 * the first key signs, every key verifies. The value names no key, so the
 * wire format is the same either way.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RequestStateCodec
{
    /** Below this the MAC — the only thing making the blob trustworthy — is forgeable. */
    public const MINIMUM_KEY_BYTES = 32;

    private const ALGORITHM = 'sha256';

    /** @var non-empty-list<string> */
    private readonly array $keys;

    /**
     * @param string|list<string> $key the signing key, or a key ring whose first key signs and whose every key verifies
     */
    public function __construct(
        string|array $key,
        private readonly int $ttlSeconds = 600,
    ) {
        $keys = \is_string($key) ? [$key] : $key;

        if ([] === $keys || !array_is_list($keys)) {
            throw new InvalidArgumentException('The requestState key ring must be a non-empty list of keys.');
        }

        foreach ($keys as $i => $candidate) {
            $name = \is_string($key) ? 'The requestState signing key' : \sprintf('The requestState signing key at index %d', $i);

            if (!\is_string($candidate)) {
                throw new InvalidArgumentException(\sprintf('%s must be a string, got %s.', $name, get_debug_type($candidate)));
            }

            if (\strlen($candidate) < self::MINIMUM_KEY_BYTES) {
                throw new InvalidArgumentException(\sprintf('%s must be at least %d bytes, got %d.', $name, self::MINIMUM_KEY_BYTES, \strlen($candidate)));
            }
        }

        $this->keys = $keys;

        if ($this->ttlSeconds < 1) {
            throw new InvalidArgumentException(\sprintf('The requestState TTL must be at least one second, got %d.', $this->ttlSeconds));
        }
    }

    /**
     * @param array<string, mixed> $payload server context to carry to the retry — never secrets
     */
    public function mint(array $payload, ?int $now = null): string
    {
        $body = self::encode(json_encode([
            'exp' => ($now ?? time()) + $this->ttlSeconds,
            'data' => $payload,
        ], \JSON_THROW_ON_ERROR));

        return $body.'.'.self::encode(self::sign($body, $this->keys[0]));
    }

    /**
     * @return array<string, mixed> the payload that was sealed
     *
     * @throws RequestStateException when the value is malformed, signed by none of the keys, or expired
     */
    public function verify(string $state, ?int $now = null): array
    {
        $parts = explode('.', $state);

        if (2 !== \count($parts)) {
            throw new RequestStateException('malformed');
        }

        [$body, $mac] = $parts;

        $actual = self::decode($mac);

        if (null === $actual) {
            throw new RequestStateException('mac');
        }

        // Every key, no early exit: which key signed is nobody's business. A
        // ring holds a handful of keys, so trying each costs next to nothing
        // and spares the wire format a key id.
        $verified = false;

        foreach ($this->keys as $key) {
            // Constant-time: a short-circuiting compare is a byte-at-a-time oracle.
            $verified = hash_equals(self::sign($body, $key), $actual) || $verified;
        }

        if (!$verified) {
            throw new RequestStateException('mac');
        }

        $decoded = self::decode($body);

        if (null === $decoded) {
            throw new RequestStateException('malformed');
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RequestStateException('malformed');
        }

        if (!\is_array($claims) || !\is_int($claims['exp'] ?? null) || !\is_array($claims['data'] ?? null)) {
            throw new RequestStateException('malformed');
        }

        // Bounds replay; does not make the state single-use.
        if ($claims['exp'] < ($now ?? time())) {
            throw new RequestStateException('expired');
        }

        return $claims['data'];
    }

    private static function sign(string $body, string $key): string
    {
        return hash_hmac(self::ALGORITHM, $body, $key, true);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
