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

use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Client\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

/**
 * The SDK's client against the SDK's server, both on 2026-07-28.
 *
 * {@see StatelessLifecycleTest} drives the same example with hand-built HTTP,
 * which proves the server answers a conforming client. This proves the other
 * half: that the client *is* one — no handshake, an envelope on every request,
 * headers the server's own validator accepts, and a multi round-trip call
 * completed without the caller doing anything special.
 */
class StatelessClientTest extends TestCase
{
    private const SERVER = __DIR__.'/../../examples/server/stateless-lifecycle/server.php';

    private Process $server;
    private int $port;

    protected function setUp(): void
    {
        $this->port = 8900 + (getmypid() % 300);

        $this->server = new Process(['php', '-S', \sprintf('127.0.0.1:%d', $this->port), self::SERVER]);
        $this->server->start();

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The example server did not start: %s', $this->server->getErrorOutput()));
    }

    protected function tearDown(): void
    {
        ServerProcess::stop($this->server);
    }

    #[TestDox('connects without a handshake and learns who it is talking to')]
    public function testConnectWithoutHandshake(): void
    {
        $client = $this->connect();

        $this->assertTrue($client->isConnected());
        $this->assertSame(ProtocolVersion::V2026_07_28, $client->getProtocolVersion());
        $this->assertSame('Stateless Lifecycle Demo', $client->getServerInfo()?->name);
        $this->assertTrue($client->getServerCapabilities()?->tools);

        $client->disconnect();
    }

    #[TestDox('lists and calls a tool, which the server accepts on the first try')]
    public function testToolCall(): void
    {
        $client = $this->connect();

        $tools = $client->listTools();
        $this->assertContains('get_weather', array_map(static fn ($tool) => $tool->name, $tools->tools));

        // A header the server disagreed with would come back as -32020, so a
        // plain result is also the assertion that the mirroring was right.
        $result = $client->callTool('get_weather', ['city' => 'Munich']);

        $this->assertStringContainsString('Munich', self::text($result));

        $client->disconnect();
    }

    #[TestDox('completes a multi round-trip call by answering the server itself')]
    public function testMultiRoundTripIsTransparentToTheCaller(): void
    {
        $client = $this->connect(elicitation: true);

        // One call from here; two on the wire. The server asks for a name, the
        // client answers from its own handler and retries with the sealed
        // `requestState`, and the caller only ever sees the finished result.
        $result = $client->callTool('greet', []);

        $this->assertStringContainsString('Hello, Ada!', self::text($result));

        $client->disconnect();
    }

    #[TestDox('defers and resumes tool input')]
    public function testDefersAndResumesToolInput(): void
    {
        $calls = [];
        $httpClient = $this->createMock(ClientInterface::class);
        $delegate = Psr18ClientDiscovery::find();
        $httpClient->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$calls, $delegate): ResponseInterface {
            $body = json_decode((string) $request->getBody(), flags: \JSON_THROW_ON_ERROR);
            if ('tools/call' === ($body->method ?? null)) {
                $calls[] = $body;
            }

            return $delegate->sendRequest($request);
        });

        $client = $this->connect(elicitation: true, httpClient: $httpClient);

        $pending = $client->callToolRound('greet');

        $this->assertInstanceOf(InputRequiredResult::class, $pending, 'Received '.get_debug_type($pending).' instead of being able to defer the tool call.');
        $this->assertInstanceOf(ElicitRequest::class, $pending->inputRequests['who']);
        $this->assertSame('What name should the greeting use?', $pending->inputRequests['who']->message);
        $this->assertNotNull($pending->requestState);
        $this->assertCount(1, $calls);
        $this->assertObjectNotHasProperty('inputResponses', $calls[0]->params);
        $this->assertObjectNotHasProperty('requestState', $calls[0]->params);

        $result = $client->callToolRound(
            'greet',
            inputResponses: ['who' => new ElicitResult(ElicitAction::Accept, ['name' => 'Grace'])],
            requestState: $pending->requestState,
        );

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertSame('Hello, Grace!', self::text($result));
        $this->assertCount(2, $calls);
        $this->assertNotSame($calls[0]->id, $calls[1]->id);
        $this->assertSame($pending->requestState, $calls[1]->params->requestState);
        $this->assertSame('Grace', $calls[1]->params->inputResponses->who->content->name);

        $client->disconnect();
    }

    #[TestDox('a capability the client never declared is refused up front, and costs only that call')]
    public function testUndeclaredCapabilityIsRefused(): void
    {
        // No elicitation capability, and the envelope says so on every request,
        // so the server refuses rather than asking for something that could
        // never be answered.
        $client = $this->connect();

        try {
            $client->callTool('greet', []);
            $this->fail('Expected the server to refuse the undeclared capability.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('did not declare it can provide', $e->getMessage());
        }

        // The connection is stateless, so a failed call costs nothing.
        $this->assertStringContainsString('Munich', self::text($client->callTool('get_weather', ['city' => 'Munich'])));

        $client->disconnect();
    }

    private static function text(CallToolResult $result): string
    {
        $first = $result->content[0] ?? null;

        self::assertInstanceOf(TextContent::class, $first);

        return $first->text;
    }

    private function connect(bool $elicitation = false, ?ClientInterface $httpClient = null): Client
    {
        $builder = Client::builder()
            ->setClientInfo('stateless-integration-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setRequestTimeout(10);

        if ($elicitation) {
            $builder->setCapabilities(new ClientCapabilities(elicitation: true));
            $builder->addRequestHandler(new class implements RequestHandlerInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof ElicitRequest;
                }

                public function handle(Request $request): Response
                {
                    return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, ['name' => 'Ada']));
                }
            });
        }

        $client = $builder->build();
        $client->connect(new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port), httpClient: $httpClient));

        return $client;
    }
}
