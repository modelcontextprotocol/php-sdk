<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Server\Http;

final class HttpRevoltTest extends HttpInspectorSnapshotTestCase
{
    public static function provideMethods(): array
    {
        return [
            ...parent::provideMethods(),
            'Wait Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'wait',
                    'toolArgs' => ['seconds' => '0.2'],
                ],
                'testName' => 'wait',
            ],
            'Check Services Tool' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'check_services',
                    'toolArgs' => [],
                ],
                'testName' => 'check_services',
            ],
        ];
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 4).'/examples/server/revolt/server.php';
    }

    protected function getServerCommand(string $address): array
    {
        return ['php', $this->getServerScript(), $address];
    }
}
