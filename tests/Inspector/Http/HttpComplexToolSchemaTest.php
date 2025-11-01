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

final class HttpComplexToolSchemaTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Schedule Event (Meeting with Time)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schedule_event',
                    'toolArgs' => [
                        'title' => 'Team Standup',
                        'date' => '2024-12-01',
                        'type' => 'meeting',
                        'time' => '09:00',
                        'priority' => 'normal',
                        'attendees' => ['alice@example.com', 'bob@example.com'],
                        'sendInvites' => true,
                    ],
                ],
                'testName' => 'schedule_event_meeting_with_time',
            ],
            'Schedule Event (All Day Reminder)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schedule_event',
                    'toolArgs' => [
                        'title' => 'Project Deadline',
                        'date' => '2024-12-15',
                        'type' => 'reminder',
                        'priority' => 'high',
                    ],
                ],
                'testName' => 'schedule_event_all_day_reminder',
            ],
            'Schedule Event (Call with High Priority)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schedule_event',
                    'toolArgs' => [
                        'title' => 'Client Call',
                        'date' => '2024-12-02',
                        'type' => 'call',
                        'time' => '14:30',
                        'priority' => 'high',
                        'attendees' => ['client@example.com'],
                        'sendInvites' => false,
                    ],
                ],
                'testName' => 'schedule_event_high_priority',
            ],
            'Schedule Event (Other Event with Low Priority)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schedule_event',
                    'toolArgs' => [
                        'title' => 'Office Party',
                        'date' => '2024-12-20',
                        'type' => 'other',
                        'time' => '18:00',
                        'priority' => 'low',
                        'attendees' => ['team@company.com'],
                    ],
                ],
                'testName' => 'schedule_event_low_priority',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/complex-tool-schema/server.php';
    }
}
