<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Wire;

/**
 * The HTTP header vocabulary that mirrors a request's JSON-RPC body (SEP-2243).
 *
 * Both sides of the wire need the same answers — which header carries a
 * method's subject, and how a value that is not header-safe is wrapped — so
 * the rules live here rather than once in the client's emitter and again in
 * the server's validator, where they could quietly disagree.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports#http-headers
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class McpHeader
{
    public const METHOD = 'Mcp-Method';
    public const NAME = 'Mcp-Name';
    public const PARAM_PREFIX = 'Mcp-Param-';
    public const PROTOCOL_VERSION = 'MCP-Protocol-Version';

    /** Wrapper marking a header value as Base64 of its UTF-8 representation. */
    private const BASE64_PREFIX = '=?base64?';
    private const BASE64_SUFFIX = '?=';

    /**
     * The subject of a request, per method. Anything unlisted is exempt.
     *
     * @param array<string, mixed>|null $params
     */
    public static function nameFor(string $method, ?array $params): ?string
    {
        $value = match ($method) {
            'tools/call', 'prompts/get' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            'tasks/get', 'tasks/update', 'tasks/cancel' => $params['taskId'] ?? null,
            default => null,
        };

        return \is_string($value) ? $value : null;
    }

    /**
     * Renders a mirrored argument as a header value, wrapping it when it is not
     * header-safe.
     *
     * Booleans travel as `true`/`false` and integers as decimal digits, both of
     * which are always safe. A string is wrapped when it carries a control
     * character, anything outside US-ASCII, or leading or trailing whitespace —
     * the last because a receiver is entitled to trim the value (RFC 9110
     * §5.5), which would otherwise silently change it.
     *
     * Returns null for a value that cannot be mirrored at all.
     */
    public static function encode(mixed $value): ?string
    {
        $rendered = match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_string($value) => $value,
            default => null,
        };

        if (null === $rendered) {
            return null;
        }

        return self::isSafe($rendered) ? $rendered : self::wrap($rendered);
    }

    /**
     * Unwraps a `=?base64?…?=` value, or returns a plain value unchanged.
     *
     * Strict: PHP's decoder accepts mispadded input and returns plausible
     * bytes, which would turn a corrupted header into a silent mismatch.
     * Null when the wrapper is present but its contents are not valid Base64.
     */
    public static function decode(string $value): ?string
    {
        if (!str_starts_with($value, self::BASE64_PREFIX) || !str_ends_with($value, self::BASE64_SUFFIX)) {
            return $value;
        }

        $encoded = substr($value, \strlen(self::BASE64_PREFIX), -\strlen(self::BASE64_SUFFIX));

        $decoded = base64_decode($encoded, true);

        if (false === $decoded || base64_encode($decoded) !== $encoded) {
            return null;
        }

        return $decoded;
    }

    /**
     * Validates every `x-mcp-header` annotation reachable through `properties`
     * in an input schema (SEP-2243).
     *
     * The value becomes an HTTP field name, so it has to be one; it has to be
     * unique case-insensitively, or two arguments would fight over one header;
     * and it may only sit on a primitive that is not `number`, because a float
     * has no single decimal spelling for a receiver to compare against.
     *
     * @param array<string, mixed> $schema
     *
     * @return string|null the reason it is invalid, or null when every annotation is well-formed
     */
    public static function checkAnnotations(array $schema): ?string
    {
        $seen = [];

        foreach (self::annotations($schema) as [$name, $type, $path]) {
            if ('' === $name) {
                return \sprintf('the annotation at "%s" is empty', $path);
            }

            // RFC 9110 tchar; excludes CR, LF and every other control character.
            if (1 !== preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name)) {
                return \sprintf('"%s" is not a valid HTTP field name', $name);
            }

            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                return \sprintf('"%s" is declared twice, at "%s" and "%s"', $name, $seen[$folded], $path);
            }
            $seen[$folded] = $path;

            if ('number' === $type) {
                return \sprintf('"%s" is on a "number" property ("%s"), which cannot be mirrored', $name, $path);
            }

            if (null !== $type && !\in_array($type, ['string', 'integer', 'boolean'], true)) {
                return \sprintf('"%s" is on a "%s" property ("%s"); only string, integer and boolean can be mirrored', $name, $type, $path);
            }
        }

        return null;
    }

    /**
     * Every annotation reachable through `properties` alone, as name, declared
     * type and dotted path.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array{string, ?string, string}>
     */
    public static function annotations(array $schema, string $prefix = ''): array
    {
        $properties = $schema['properties'] ?? null;

        if (!\is_array($properties)) {
            return [];
        }

        $found = [];

        foreach ($properties as $property => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $path = '' === $prefix ? (string) $property : $prefix.'.'.$property;
            $annotation = $definition['x-mcp-header'] ?? null;

            if (null !== $annotation) {
                if (!\is_string($annotation)) {
                    $found[] = ['', null, $path];
                } else {
                    $found[] = [$annotation, \is_string($definition['type'] ?? null) ? $definition['type'] : null, $path];
                }
            }

            $found = [...$found, ...self::annotations($definition, $path)];
        }

        return $found;
    }

    /**
     * Case-insensitive header lookup, since PSR-7 preserves the sender's casing.
     *
     * @param array<string, string> $headers
     */
    public static function lookup(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return '' === $value ? null : $value;
            }
        }

        return null;
    }

    private static function wrap(string $value): string
    {
        return self::BASE64_PREFIX.base64_encode($value).self::BASE64_SUFFIX;
    }

    /**
     * Printable US-ASCII with no leading or trailing whitespace. Interior
     * spaces are fine; a tab is not, since it is a control character that
     * field parsers are allowed to fold.
     *
     * A literal that already has the wrapper's shape is not safe either: sent
     * unchanged, {@see self::decode()} would unwrap it as if it were Base64
     * and hand back something other than the literal.
     */
    private static function isSafe(string $value): bool
    {
        if ($value !== trim($value)) {
            return false;
        }

        if (str_starts_with($value, self::BASE64_PREFIX) && str_ends_with($value, self::BASE64_SUFFIX)) {
            return false;
        }

        return 1 === preg_match('/^[\x20-\x7E]*$/', $value);
    }
}
