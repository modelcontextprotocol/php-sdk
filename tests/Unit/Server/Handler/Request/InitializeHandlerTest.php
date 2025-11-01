<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class InitializeHandlerTest extends TestCase
{
    #[TestDox('uses configuration protocol version when provided')]
    public function testHandleUsesConfigurationProtocolVersion(): void
    {
        $customProtocolVersion = ProtocolVersion::V2024_11_05;

        $configuration = new Configuration(
            serverInfo: new Implementation('server', '1.2.3'),
            capabilities: new ServerCapabilities(),
            protocolVersion: $customProtocolVersion,
        );

        $handler = new InitializeHandler($configuration);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())
            ->method('set')
            ->with('client_info', [
                'name' => 'client-app',
                'version' => '1.0.0',
            ]);

        $request = InitializeRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 'request-1',
            'method' => InitializeRequest::getMethod(),
            'params' => [
                'protocolVersion' => ProtocolVersion::V2024_11_05->value,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'client-app',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $response = $handler->handle($request, $session);

        $this->assertInstanceOf(InitializeResult::class, $response->result);

        /** @var InitializeResult $result */
        $result = $response->result;

        $this->assertSame($customProtocolVersion, $result->protocolVersion);
        $this->assertSame(
            $customProtocolVersion->value,
            $result->jsonSerialize()['protocolVersion']
        );
    }
}
