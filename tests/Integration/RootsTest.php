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
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use Mcp\Server\Builder as ServerBuilder;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Roots: the server asking the client which workspace folders it may touch.
 */
final class RootsTest extends IntegrationTestCase
{
    #[TestDox('the roots the client exposes reach the tool that asked')]
    public function testRootsReachTheTool(): void
    {
        $client = $this->connect($this->serverWithRootsTool(), $this->clientExposing(
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
        $client = $this->connect($this->serverWithRootsTool(), $this->clientExposing());

        $result = $client->callTool('inspect_roots');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('', $result->content[0]->text);
    }

    #[TestDox('a client that does not advertise roots is not asked')]
    public function testCapabilityIsVisibleToTheServer(): void
    {
        $client = $this->connect($this->serverWithRootsTool());

        $result = $client->callTool('inspect_roots');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('unsupported', $result->content[0]->text);
    }

    #[TestDox('the client can announce that its roots changed')]
    public function testRootsListChangedNotification(): void
    {
        $client = $this->connect($this->serverWithRootsTool(), $this->clientExposing(new Root('file:///workspace')));

        // A notification has no reply, so what this pins down is that sending one
        // mid-session neither raises nor leaves the connection unusable.
        $client->sendRootsListChanged();

        $this->assertTrue($client->isConnected());
        $this->assertInstanceOf(TextContent::class, $client->callTool('inspect_roots')->content[0]);
    }

    private function serverWithRootsTool(): ServerBuilder
    {
        return $this->serverBuilder()->addTool(
            static function (RequestContext $context): string {
                $gateway = $context->getClientGateway();

                if (!$gateway->supportsRoots()) {
                    return 'unsupported';
                }

                $described = [];
                foreach ($gateway->listRoots()->roots as $root) {
                    $described[] = \sprintf('%s (%s)', $root->uri, $root->name ?? '-');
                }

                return implode(', ', $described);
            },
            name: 'inspect_roots',
            description: 'Reports the workspace roots the client exposes.',
        );
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
