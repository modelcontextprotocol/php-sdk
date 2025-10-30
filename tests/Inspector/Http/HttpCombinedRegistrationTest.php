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

final class HttpCombinedRegistrationTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Manual Greeter Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'manualGreeter',
                    'toolArgs' => ['user' => 'HTTP Test User'],
                ],
                'testName' => 'manual_greeter',
            ],
            'Discovered Status Check Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'discovered_status_check',
                    'toolArgs' => [],
                ],
                'testName' => 'discovered_status_check',
            ],
            'Read Priority Config (Manual Override)' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'config://priority',
                ],
                'testName' => 'config_priority',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/combined-registration/server.php';
    }
}
