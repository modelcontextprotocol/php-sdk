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
 * lexicographic order of the values — is what every comparison here relies on.
 * Revision identifiers happen to be ISO dates today, but they are an enumerated
 * set rather than an ordered scalar: future identifiers are not guaranteed to be
 * date-shaped, and an unrecognized peer string must compare conservatively
 * instead of accidentally sorting above a known revision.
 *
 * The revisions split into two eras:
 *
 *  - **handshake** (`2025-11-25` and earlier) negotiate a version through the
 *    `initialize` round-trip and keep session state for the connection;
 *  - **modern** ({@see self::FIRST_MODERN_VERSION} and later) drop `initialize`
 *    entirely — every request carries its own version in `_meta`, and servers
 *    advertise what they speak through `server/discover`.
 *
 * The two lists are deliberately kept apart so that adding a revision to one era
 * can never leak a version string into the other era's negotiation.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/versioning
 *
 * @author Illia Vasylevskyi<ineersa@gmail.com>
 */
enum ProtocolVersion: string
{
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
    public const DEFAULT_NEGOTIATED_VERSION = self::V2025_03_26;

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
