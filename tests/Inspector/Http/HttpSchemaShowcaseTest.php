<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector\Http;

final class HttpSchemaShowcaseTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Format Text Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'format_text',
                    'toolArgs' => ['text' => 'Hello World Test', 'format' => 'uppercase'],
                ],
                'testName' => 'format_text',
            ],
            'Calculate Range Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'calculate_range',
                    'toolArgs' => ['first' => 10, 'second' => 5, 'operation' => 'multiply', 'precision' => 2],
                ],
                'testName' => 'calculate_range',
            ],
            'Validate Profile Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'validate_profile',
                    'toolArgs' => [
                        'profile' => ['name' => 'John Doe', 'email' => 'john@example.com', 'age' => 30, 'role' => 'user'],
                    ],
                ],
                'testName' => 'validate_profile',
            ],
            'Manage List Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'manage_list',
                    'toolArgs' => [
                        'items' => ['apple', 'banana', 'cherry', 'date'],
                        'action' => 'sort',
                    ],
                ],
                'testName' => 'manage_list',
            ],
            'Generate Config Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'generate_config',
                    'toolArgs' => [
                        'appName' => 'TestApp',
                        'baseUrl' => 'https://example.com',
                        'environment' => 'development',
                        'debug' => true,
                        'port' => 8080,
                    ],
                ],
                'testName' => 'generate_config',
            ],
            'Schedule Event Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schedule_event',
                    'toolArgs' => [
                        'title' => 'Team Meeting',
                        'startTime' => '2024-12-01T14:30:00Z',
                        'durationHours' => 1.5,
                        'priority' => 'high',
                        'attendees' => ['alice@example.com', 'bob@example.com'],
                    ],
                ],
                'testName' => 'schedule_event',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/http-schema-showcase/server.php';
    }

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        return match ($testName) {
            'validate_profile' => preg_replace(
                '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
                '2025-01-01 00:00:00',
                $output
            ),
            'generate_config' => preg_replace(
                '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
                '2025-01-01T00:00:00+00:00',
                $output
            ),
            'schedule_event' => preg_replace([
                '/event_[a-f0-9]{13,}/',
                '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
            ], [
                'event_test123456789',
                '2025-01-01T00:00:00+00:00',
            ], $output),
            default => $output,
        };
    }
}
