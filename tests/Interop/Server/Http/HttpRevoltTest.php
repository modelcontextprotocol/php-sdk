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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\Attributes\TestDox;

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

    #[TestDox('a 2026-07-28 subscriptions/listen stream is written by the server, kept alive while idle')]
    public function testListenIsStreamed(): void
    {
        $version = ProtocolVersion::V2026_07_28->value;
        $output = $this->readFor(1.0, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'subscriptions/listen', 'params' => [
            'notifications' => ['toolsListChanged' => true],
            '_meta' => [RequestMeta::PROTOCOL_VERSION => $version, RequestMeta::CLIENT_CAPABILITIES => new \stdClass()],
        ]], ['MCP-Protocol-Version' => $version, 'Mcp-Method' => 'subscriptions/listen']);

        $this->assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $output);
        $this->assertMatchesRegularExpression('/data: \{[^\n]*"notifications\/subscriptions\/acknowledged"[^\n]*"toolsListChanged":true/', $output);
        $this->assertStringContainsString(": keep-alive\n\n", $output);
    }
}
