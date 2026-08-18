<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension;

/**
 * The naming rules an extension identifier has to satisfy (SEP-2133).
 *
 * Identifiers are `_meta` keys, with the prefix made mandatory: an extension is
 * something a vendor owns, and an unprefixed name has no owner. The
 * `modelcontextprotocol`/`mcp` second label is reserved for official
 * extensions, so a third party naming itself `io.modelcontextprotocol/tasks`
 * would be claiming to be one.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#meta
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ExtensionIdentifier
{
    /** Second labels only the specification may use. */
    public const RESERVED_LABELS = ['modelcontextprotocol', 'mcp'];

    /** Prefixes the specification itself allocates. */
    public const OFFICIAL_PREFIX = 'io.modelcontextprotocol/';

    /** A label: starts with a letter, ends alphanumeric, hyphens inside. */
    private const LABEL = '[a-zA-Z](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?';

    /** A name: alphanumeric at both ends, `-`, `_` and `.` inside. */
    private const NAME = '[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?';

    /**
     * @return string|null the reason $identifier is invalid, or null when it is well-formed
     */
    public static function check(string $identifier): ?string
    {
        $slash = strpos($identifier, '/');

        if (false === $slash) {
            return \sprintf('"%s" has no prefix; an extension identifier must be prefixed, e.g. "com.example/my-extension".', $identifier);
        }

        $prefix = substr($identifier, 0, $slash);
        $name = substr($identifier, $slash + 1);

        if (1 !== preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/', $prefix)) {
            return \sprintf('"%s" is not a valid prefix: labels must start with a letter, end alphanumeric, and be separated by dots.', $prefix);
        }

        if ('' === $name || 1 !== preg_match('/^'.self::NAME.'$/', $name)) {
            return \sprintf('"%s" is not a valid extension name: it must start and end alphanumeric.', $name);
        }

        return null;
    }

    /**
     * Whether $identifier claims a prefix the specification reserves.
     *
     * Not an error on its own — the official extensions legitimately use it —
     * but a third party doing so is misrepresenting itself, so callers that are
     * not the SDK should refuse.
     */
    public static function isReserved(string $identifier): bool
    {
        $labels = explode('.', strstr($identifier, '/', true) ?: '');

        return \in_array($labels[1] ?? '', self::RESERVED_LABELS, true);
    }
}
