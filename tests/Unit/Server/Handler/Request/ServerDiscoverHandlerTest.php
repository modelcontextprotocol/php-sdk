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
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\ServerDiscoverHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Test double for server/discover requests.
 */
final class TestServerDiscoverRequest extends Request
{
    public static function getMethod(): string
    {
        return 'server/discover';
    }

    protected static function fromParams(?array $params): static
    {
        return new self();
    }

    protected function getParams(): ?array
    {
        return null;
    }
}

/**
 * Test double for other request methods.
 */
final class TestOtherRequest extends Request
{
    public static function getMethod(): string
    {
        return 'initialize';
    }

    protected static function fromParams(?array $params): static
    {
        return new self();
    }

    protected function getParams(): ?array
    {
        return null;
    }
}

final class ServerDiscoverHandlerTest extends TestCase
{
    #[TestDox('supports server/discover method')]
    public function testSupportsServerDiscoverMethod(): void
    {
        $handler = new ServerDiscoverHandler();

        $request = TestServerDiscoverRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 1,
            'method' => 'server/discover',
            'params' => [],
        ]);

        $this->assertTrue($handler->supports($request));
    }

    #[TestDox('does not support other methods')]
    public function testDoesNotSupportOtherMethods(): void
    {
        $handler = new ServerDiscoverHandler();

        $request = TestOtherRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $this->assertFalse($handler->supports($request));
    }

    #[TestDox('handle returns InitializeResult with configuration data')]
    public function testHandleReturnsInitializeResult(): void
    {
        $serverInfo = new Implementation('test-server', '1.0.0', 'Test description');
        $capabilities = new ServerCapabilities(tools: true, resources: true);
        $configuration = new Configuration(
            serverInfo: $serverInfo,
            capabilities: $capabilities,
            paginationLimit: 25,
            instructions: 'Be helpful',
            protocolVersion: ProtocolVersion::V2025_11_25,
        );

        $handler = new ServerDiscoverHandler($configuration);

        $request = TestServerDiscoverRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 42,
            'method' => 'server/discover',
            'params' => [],
        ]);

        $session = $this->createMock(SessionInterface::class);

        $response = $handler->handle($request, $session);

        $this->assertSame(42, $response->getId());
        $this->assertInstanceOf(InitializeResult::class, $response->result);

        /** @var InitializeResult $result */
        $result = $response->result;

        $this->assertSame('test-server', $result->serverInfo->name);
        $this->assertSame('1.0.0', $result->serverInfo->version);
        $this->assertSame('Test description', $result->serverInfo->description);
        $this->assertTrue($result->capabilities->tools);
        $this->assertTrue($result->capabilities->resources);
        $this->assertSame('Be helpful', $result->instructions);
        $this->assertSame(ProtocolVersion::V2025_11_25, $result->protocolVersion);
    }

    #[TestDox('handle falls back to defaults when configuration is minimal')]
    public function testHandleFallsBackToDefaults(): void
    {
        $configuration = new Configuration(
            serverInfo: new Implementation('test', '1.0.0'),
            capabilities: new ServerCapabilities(),
        );

        $handler = new ServerDiscoverHandler($configuration);

        $request = TestServerDiscoverRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 1,
            'method' => 'server/discover',
            'params' => [],
        ]);

        $session = $this->createMock(SessionInterface::class);

        $response = $handler->handle($request, $session);

        $this->assertSame(1, $response->getId());
        $this->assertInstanceOf(InitializeResult::class, $response->result);

        /** @var InitializeResult $result */
        $result = $response->result;

        $this->assertSame('test', $result->serverInfo->name);
        $this->assertSame('1.0.0', $result->serverInfo->version);
        $this->assertNull($result->instructions);
    }
}
