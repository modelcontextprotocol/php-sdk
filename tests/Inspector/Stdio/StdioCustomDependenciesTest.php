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

final class StdioCustomDependenciesTest extends StdioInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Add Task' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'add_task',
                    'toolArgs' => ['userId' => 'alice', 'description' => 'Complete the project documentation'],
                ],
                'testName' => 'add_task',
            ],
            'List User Tasks' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'list_user_tasks',
                    'toolArgs' => ['userId' => 'alice'],
                ],
                'testName' => 'list_user_tasks',
            ],
            'Complete Task' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'complete_task',
                    'toolArgs' => ['taskId' => 1],
                ],
                'testName' => 'complete_task',
            ],
            'Read System Statistics Resource' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'stats://system/overview',
                ],
                'testName' => 'read_system_stats',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/server/custom-dependencies/server.php';
    }

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        return match ($testName) {
            'add_task' => preg_replace(
                '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
                '2025-01-01T00:00:00+00:00',
                $output
            ),
            'read_system_stats' => preg_replace(
                '/\\\\"server_uptime_seconds\\\\": -?\d+\.?\d*/',
                '\\"server_uptime_seconds\\": 12345',
                $output
            ),
            default => $output,
        };
    }
}
