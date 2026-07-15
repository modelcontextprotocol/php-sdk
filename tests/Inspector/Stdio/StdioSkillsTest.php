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

final class StdioSkillsTest extends StdioInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Read Skill Manifest' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'skill://code-review/SKILL.md',
                ],
                'testName' => 'read_skill_md',
            ],
            'Read Skill Supporting File' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'skill://code-review/references/SECURITY.md',
                ],
                'testName' => 'read_supporting_file',
            ],
            'Read Skill Discovery Index' => [
                'method' => 'resources/read',
                'options' => [
                    'uri' => 'skill://index.json',
                ],
                'testName' => 'read_discovery_index',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 3).'/examples/server/skills/server.php';
    }
}
