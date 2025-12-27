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

final class HttpDiscoveryUserProfileTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Send Welcome Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'send_welcome',
                    'toolArgs' => ['userId' => '101', 'customMessage' => 'Welcome to our platform!'],
                ],
                'testName' => 'send_welcome',
            ],
            'Test Tool Without Params' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'test_tool_without_params',
                    'toolArgs' => [],
                ],
                'testName' => 'test_tool_without_params',
            ],
            'Read User Profile 101' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'user://101/profile',
                ],
                'testName' => 'read_user_profile_101',
            ],
            'Read User Profile 102' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'user://102/profile',
                ],
                'testName' => 'read_user_profile_102',
            ],
            'Read User ID List' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'user://list/ids',
                ],
                'testName' => 'read_user_id_list',
            ],
            'Generate Bio Prompt (Formal)' => [
                'method' => 'prompts/get',
                'options' => [
                    'promptName' => 'generate_bio_prompt',
                    'promptArgs' => ['userId' => '101', 'tone' => 'formal'],
                ],
                'testName' => 'generate_bio_prompt',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/server/discovery-userprofile/server.php';
    }
}
