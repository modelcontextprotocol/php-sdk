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

final class StdioDiscoveryCalculatorTest extends InspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideListMethods(),
            'Calculate Sum' => [
                'method' => 'tools/call',
                'toolName' => 'calculate',
                'toolArgs' => ['a' => 12.5, 'b' => 7.3, 'operation' => 'add'],
            ],
            'Read Config' => [
                'method' => 'resources/read',
                'toolName' => null, // can be removed with newer PHPUnit versions
                'toolArgs' => [], // can be removed with newer PHPUnit versions
                'uri' => 'config://calculator/settings',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 2).'/examples/stdio-discovery-calculator/server.php';
    }
}
