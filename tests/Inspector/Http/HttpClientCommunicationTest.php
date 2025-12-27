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

final class HttpClientCommunicationTest extends HttpInspectorSnapshotTestCase
{
    protected function setUp(): void
    {
        $this->markTestSkipped('Test skipped: SDK cannot handle logging/setLevel requests required by logging capability, and built-in PHP server does not support sampling.');
    }

    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Prepare Project Briefing (Simple)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'prepare_project_briefing',
                    'toolArgs' => [
                        'projectName' => 'Website Redesign',
                        'milestones' => ['Discovery', 'Design', 'Development', 'Testing'],
                    ],
                ],
                'testName' => 'prepare_project_briefing_simple',
            ],
            'Prepare Project Briefing (Complex)' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'prepare_project_briefing',
                    'toolArgs' => [
                        'projectName' => 'Mobile App Launch',
                        'milestones' => ['Market Research', 'UI/UX Design', 'MVP Development', 'Beta Testing', 'Marketing Campaign', 'Public Launch'],
                    ],
                ],
                'testName' => 'prepare_project_briefing_complex',
            ],
            'Run Service Maintenance' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'run_service_maintenance',
                    'toolArgs' => [
                        'serviceName' => 'Payment Gateway API',
                    ],
                ],
                'testName' => 'run_service_maintenance',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/server/client-communication/server.php';
    }
}
