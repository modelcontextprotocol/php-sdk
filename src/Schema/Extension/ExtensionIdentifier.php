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

use Mcp\Exception\InvalidArgumentException;

/**
 * An extension identifier (SEP-2133): a `_meta` key with a mandatory vendor
 * prefix, since an extension is something a vendor owns and an unprefixed
 * name has no owner. Naming rules are enforced at construction, so any
 * `ExtensionIdentifier` in hand is guaranteed well-formed.
 *
 * The `modelcontextprotocol`/`mcp` second label is reserved for official
 * extensions, so a third party naming itself `io.modelcontextprotocol/tasks`
 * would be claiming to be one — see {@see self::isReserved()}.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#meta
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ExtensionIdentifier implements \Stringable
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
     * @throws InvalidArgumentException if $identifier is not a valid `_meta` prefix
     */
    public function __construct(
        private readonly string $identifier,
    ) {
        if (null !== $reason = self::check($identifier)) {
            throw new InvalidArgumentException($reason);
        }
    }

    /**
     * Whether this identifier claims a prefix the specification reserves.
     *
     * Not an error on its own — the official extensions legitimately use it —
     * but a third party doing so is misrepresenting itself, so callers that are
     * not the SDK should refuse.
     */
    public function isReserved(): bool
    {
        $labels = explode('.', strstr($this->identifier, '/', true) ?: '');

        return \in_array($labels[1] ?? '', self::RESERVED_LABELS, true);
    }

    public function __toString(): string
    {
        return $this->identifier;
    }

    /**
     * @return string|null the reason $identifier is invalid, or null when it is well-formed
     */
    private static function check(string $identifier): ?string
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
}
