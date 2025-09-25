<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector;

final class StdioCalculatorExampleTest extends InspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideListMethods(),
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 2).'/examples/01-discovery-stdio-calculator/server.php';
    }
}
