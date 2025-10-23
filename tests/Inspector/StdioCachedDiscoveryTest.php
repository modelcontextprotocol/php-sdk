<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inspector;

use Mcp\Tests\Inspector\InspectorSnapshotTestCase;

final class StdioCachedDiscoveryTest extends InspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideListMethods(),
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 2).'/examples/stdio-cached-discovery/server.php';
    }
}
