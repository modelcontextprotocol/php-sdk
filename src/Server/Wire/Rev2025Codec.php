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
 * The identity transform, deliberately: these revisions have nothing to stamp,
 * and nothing newer may leak into them.
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
