<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector\Stdio;

final class StdioDiscoveryCalculatorTest extends StdioInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Calculate Sum' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'calculate',
                    'toolArgs' => ['a' => 12.5, 'b' => 7.3, 'operation' => 'add'],
                ],
                'testName' => 'calculate_sum',
            ],
            'Update Setting' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'update_setting',
                    'toolArgs' => ['setting' => 'precision', 'value' => 3],
                ],
                'testName' => 'update_setting',
            ],
            'Read Config' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'config://calculator/settings',
                ],
                'testName' => 'read_config',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/stdio-discovery-calculator/server.php';
    }
}
