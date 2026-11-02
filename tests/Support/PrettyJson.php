<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Support;

/**
 * Keeps JSON readable in snapshots; formatter tests pin the compact wire format.
 */
final class PrettyJson
{
    public static function format(string $text): string
    {
        $decoded = json_decode($text);

        return \is_array($decoded) || \is_object($decoded)
            ? json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
            : $text;
    }
}
