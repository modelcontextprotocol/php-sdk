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
 * The wire codec for every revision through 2025-11-25.
 *
 * The identity transform, and deliberately so. These revisions have no
 * `resultType`, no caching hints and no `_meta` serverInfo requirement, so the
 * neutral result body *is* the wire body — there is nothing to stamp and,
 * more importantly, nothing newer that may leak in. A server negotiating one
 * of these revisions emits exactly the bytes it emitted before the modern era
 * existed.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Rev2025Codec implements WireCodecInterface
{
    public function encodeResult(string $method, array $result): array
    {
        return $result;
    }
}
