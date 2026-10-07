<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop;

/**
 * The third-party implementations the interop suite runs against.
 *
 * They are installed from `tests/Interop/package-lock.json` rather than fetched
 * with `npx`, which would pin a package but let its dependencies float, so a
 * new release of one of them could change what the snapshots see.
 */
final class NodeTool
{
    public static function path(string $bin): string
    {
        $path = __DIR__.'/node_modules/.bin/'.$bin;

        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('"%s" is not installed. Run `npm ci --prefix tests/Interop` first.', $bin));
        }

        return $path;
    }
}
