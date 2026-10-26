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
use Mcp\Schema\Request\DiscoverRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\DiscoverResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\DiscoverHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class DiscoverHandlerTest extends TestCase
{
    #[TestDox('supports server/discover and nothing else')]
    public function testSupportsDiscoverOnly(): void
    {
        $handler = new DiscoverHandler($this->configuration(), [ProtocolVersion::V2026_07_28]);

        $this->assertTrue($handler->supports(new DiscoverRequest()));
        $this->assertFalse($handler->supports(new PingRequest()));
    }

    #[TestDox('answers with the supported versions, capabilities and instructions')]
    public function testAnswersFromConfiguration(): void
    {
        $configuration = $this->configuration('Call list_tools first.');
        $handler = new DiscoverHandler($configuration, [ProtocolVersion::V2026_07_28]);

        $response = $handler->handle((new DiscoverRequest())->withId('d-1'), $this->createStub(SessionInterface::class));

        $this->assertSame('d-1', $response->getId());
        $this->assertInstanceOf(DiscoverResult::class, $response->result);
        $this->assertSame([ProtocolVersion::V2026_07_28], $response->result->supportedVersions);
        $this->assertSame($configuration->capabilities, $response->result->capabilities);
        $this->assertSame('Call list_tools first.', $response->result->instructions);
    }

    #[TestDox('leaves instructions out when none are configured')]
    public function testWithoutInstructions(): void
    {
        $handler = new DiscoverHandler($this->configuration(), [ProtocolVersion::V2026_07_28]);

        $response = $handler->handle((new DiscoverRequest())->withId(1), $this->createStub(SessionInterface::class));

        $this->assertArrayNotHasKey('instructions', $response->result->jsonSerialize());
    }

    private function configuration(?string $instructions = null): Configuration
    {
        return new Configuration(
            serverInfo: new Implementation('server', '1.0.0'),
            capabilities: new ServerCapabilities(tools: true),
            instructions: $instructions,
        );
    }
}
