<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Wire;

/**
 * Translates the SDK's revision-neutral result model into the wire shape one
 * protocol era expects.
 *
 * Fields that exist only from 2026-07-28 — `resultType`, the SEP-2549 caching
 * hints, the `_meta` serverInfo identity — are stamped here rather than
 * modelled on the result classes, so one set of results can serve every
 * revision without older clients receiving vocabulary they have never seen.
 *
 * Keyed by era, not version: every revision through 2025-11-25 shares one.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface WireCodecInterface
{
    /**
     * Stamps an already-serialized result with whatever this era requires.
     *
     * @param string               $method    the request method the result answers
     * @param array<string, mixed> $result    the neutral result body
     * @param bool                 $cacheable whether this answer may carry caching hints at all — false for one
     *                                        produced by a multi round-trip retry, whose inputs are not part of
     *                                        any cache key
     *
     * @return array<string, mixed>
     */
    public function encodeResult(string $method, array $result, bool $cacheable = true): array;
}
