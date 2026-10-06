<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Roots: the server asking the client which workspace folders it may touch.
 *
 * @see Fixture/roots.php for the server under test
 */
final class RootsTest extends IntegrationTestCase
{
    /**
     * Roots exists only on the handshake era: 2026-07-28 removed it, so a
     * client and server that could both settle on the modern era are kept off it.
     */
    protected function clientBuilder(): ClientBuilder
    {
        return parent::clientBuilder()->setProtocolVersion(ProtocolVersion::V2025_11_25);
    }

    #[TestDox('the roots the client exposes reach the tool that asked')]
    public function testRootsReachTheTool(): void
    {
        $client = $this->connect('roots', $this->clientExposing(
            new Root('file:///workspace/app', 'App'),
            new Root('file:///workspace/docs', 'Docs'),
        ));

        $result = $client->callTool('inspect_roots');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('file:///workspace/app (App), file:///workspace/docs (Docs)', $result->content[0]->text);
    }

    #[TestDox('an empty root list is a valid answer, not a failure')]
    public function testEmptyRootList(): void
    {
        $client = $this->connect('roots', $this->clientExposing());

        $result = $client->callTool('inspect_roots');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('', $result->content[0]->text);
    }

    #[TestDox('a client that does not advertise roots is not asked')]
    public function testCapabilityIsVisibleToTheServer(): void
    {
        $client = $this->connect('roots');

        $result = $client->callTool('inspect_roots');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('unsupported', $result->content[0]->text);
    }

    #[TestDox('the client can announce that its roots changed')]
    public function testRootsListChangedNotification(): void
    {
        $client = $this->connect('roots', $this->clientExposing(new Root('file:///workspace')));

        // A notification has no reply, so what this pins down is that sending
        // one mid-session leaves the connection usable.
        $client->sendRootsListChanged();

        $this->assertTrue($client->isConnected());
        $this->assertInstanceOf(TextContent::class, $client->callTool('inspect_roots')->content[0]);
    }

    private function clientExposing(Root ...$roots): ClientBuilder
    {
        $callback = new class(array_values($roots)) implements RootsCallbackInterface {
            /** @param list<Root> $roots */
            public function __construct(private readonly array $roots)
            {
            }

            public function __invoke(ListRootsRequest $request): ListRootsResult
            {
                return new ListRootsResult($this->roots);
            }
        };

        return $this->clientBuilder()
            ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true))
            ->addRequestHandler(new ListRootsRequestHandler($callback));
    }
}
