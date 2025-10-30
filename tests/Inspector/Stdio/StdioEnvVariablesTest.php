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

use Mcp\Tests\Inspector\Stdio\StdioInspectorSnapshotTestCase;

final class StdioEnvVariablesTest extends StdioInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Process Data (Default Mode)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'process_data_by_mode',
                    'toolArgs' => ['input' => 'test data'],
                ],
                'testName' => 'process_data_default',
            ],
            'Process Data (Debug Mode)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'process_data_by_mode',
                    'toolArgs' => ['input' => 'debug test'],
                    'envVars' => ['APP_MODE' => 'debug'],
                ],
                'testName' => 'process_data_debug',
            ],
            'Process Data (Production Mode)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'process_data_by_mode',
                    'toolArgs' => ['input' => 'production data'],
                    'envVars' => ['APP_MODE' => 'production'],
                ],
                'testName' => 'process_data_production',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/env-variables/server.php';
    }
}
