<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

use Mcp\Exception\LogicException;

/**
 * Registry of the MCP protocol revisions this SDK knows about.
 *
 * Cases are declared oldest to newest, and that declaration order — not the
 * lexicographic order of the values — is what every comparison here relies on:
 * revision identifiers are an enumerated set, not an ordered scalar, and a
 * future one is not guaranteed to be date-shaped.
 *
 * The revisions split into two eras:
 *
 *  - **handshake** (`2025-11-25` and earlier) negotiate a version through the
 *    `initialize` round-trip and keep session state for the connection;
 *  - **modern** ({@see self::FIRST_MODERN_VERSION} and later) drop `initialize`
 *    entirely — every request carries its own version in `_meta`, and servers
 *    advertise what they speak through `server/discover`.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/versioning
 *
 * @author Illia Vasylevskyi<ineersa@gmail.com>
 */
enum ProtocolVersion: string
{
    // Declaration order is also the era boundary: everything declared from
    // FIRST_MODERN_VERSION onwards is modern. A new handshake-era revision has
    // to be inserted above that case — appending it here would silently drop it
    // out of the handshake negotiation.
    case V2024_11_05 = '2024-11-05';
    case V2025_03_26 = '2025-03-26';
    case V2025_06_18 = '2025-06-18';
    case V2025_11_25 = '2025-11-25';
    case V2026_07_28 = '2026-07-28';

    /**
     * First revision of the modern era, in which the `initialize` handshake was
     * replaced by per-request metadata.
     */
    public const FIRST_MODERN_VERSION = self::V2026_07_28;

    /**
     * Version a server assumes when a client omits the `MCP-Protocol-Version`
     * header on the Streamable HTTP transport.
     *
     * This is the revision that introduced both Streamable HTTP and the header
     * itself, so a request without the header cannot be newer than this.
     */
    public const DEFAULT_HEADER_VERSION = self::V2025_03_26;

    /**
     * Newest revision reachable through the `initialize` handshake.
     *
     * This is what a server counter-offers when it cannot honour the version a
     * client asked for, and what a handshake-era client offers by default.
     */
    public static function latestHandshake(): self
    {
        $versions = self::handshakeVersions();

        return $versions[\count($versions) - 1];
    }

    /**
     * Revisions reachable through the `initialize` handshake, oldest to newest.
     *
     * @return non-empty-list<self>
     */
    public static function handshakeVersions(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $v): bool => !$v->isModern()));
    }

    /**
     * Revisions using the per-request metadata envelope, oldest to newest.
     *
     * @return non-empty-list<self>
     */
    public static function modernVersions(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $v): bool => $v->isModern()));
    }

    /**
     * Whether this revision belongs to the modern, per-request-metadata era.
     */
    public function isModern(): bool
    {
        return $this->isAtLeast(self::FIRST_MODERN_VERSION);
    }

    /**
     * Whether this revision restricts `structuredContent` to a JSON object.
     *
     * SEP-2106, part of {@see self::V2026_07_28}, widened `outputSchema` to any
     * JSON Schema 2020-12 and `structuredContent` to any JSON value conforming to
     * it. Up to `2025-11-25` both are restricted to an object.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#structured-content
     */
    public function requiresObjectStructuredContent(): bool
    {
        return !$this->isAtLeast(self::V2026_07_28);
    }

    /**
     * Whether this revision answers a missing resource with `-32602`.
     *
     * SEP-2164, part of {@see self::V2026_07_28}, retired the bespoke `-32002`
     * in favour of the JSON-RPC code that already meant this, and reserved
     * `-32002` so it is never reused. Earlier revisions still expect it, and
     * clients are told to keep accepting it from them.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#error-codes
     */
    public function usesInvalidParamsForResourceNotFound(): bool
    {
        return $this->isAtLeast(self::V2026_07_28);
    }

    /**
     * Whether this revision is at least as new as $minimum.
     */
    public function isAtLeast(self $minimum): bool
    {
        return $this->position() >= $minimum->position();
    }

    /**
     * Index of this revision in the chronological declaration order.
     */
    private function position(): int
    {
        foreach (self::cases() as $index => $case) {
            if ($case === $this) {
                return $index;
            }
        }

        throw new LogicException(\sprintf('Protocol version "%s" is not a declared case.', $this->value));
    }
}
