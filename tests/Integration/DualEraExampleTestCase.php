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

use Mcp\Client;
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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Base for tests that drive one example server from both protocol eras.
 *
 * The example is started once, on one port, and every client in the test
 * reaches it at that one URL — which is the property being tested. Nothing here
 * mounts a second endpoint or restarts anything between eras.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class DualEraExampleTestCase extends TestCase
{
    /** Answers whatever the server elicits, so a tool that asks can complete. */
    protected const ANSWERS = [];

    private Process $server;
    private int $port;

    /** Absolute path to the example's server script. */
    abstract protected static function server(): string;

    /** Port range base, so concurrent test classes do not collide. */
    abstract protected static function portBase(): int;

    protected function setUp(): void
    {
        $this->port = static::portBase() + (getmypid() % 200);

        // More than one worker because the handshake era needs it: a tool that
        // asks the client mid-call holds its SSE response open while the client
        // POSTs the answer on a second connection. One worker deadlocks there —
        // a property of `php -S`, not of the server.
        $this->server = new Process(
            ['php', '-S', \sprintf('127.0.0.1:%d', $this->port), static::server()],
            env: ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
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
        $this->server->stop();
    }

    /**
     * @return iterable<string, array{ProtocolVersion}>
     */
    public static function provideEras(): iterable
    {
        yield 'the handshake era' => [ProtocolVersion::V2025_11_25];
        yield 'the modern era' => [ProtocolVersion::V2026_07_28];
    }

    protected static function text(CallToolResult $result): string
    {
        $first = $result->content[0] ?? null;

        self::assertInstanceOf(TextContent::class, $first);

        return $first->text;
    }

    protected function connect(ProtocolVersion $era, bool $elicitation = false): Client
    {
        $builder = Client::builder()
            ->setClientInfo('dual-era-integration-client', '1.0.0')
            ->setProtocolVersion($era)
            ->setRequestTimeout(10);

        if ($elicitation) {
            $answers = static::ANSWERS;

            $builder->setCapabilities(new ClientCapabilities(elicitation: true));
            $builder->addRequestHandler(new class($answers) implements RequestHandlerInterface {
                /** @param array<string, mixed> $answers */
                public function __construct(private readonly array $answers)
                {
                }

                public function supports(Request $request): bool
                {
                    return $request instanceof ElicitRequest;
                }

                public function handle(Request $request): Response
                {
                    return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, $this->answers));
                }
            });
        }

        $client = $builder->build();
        $client->connect(new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port)));

        return $client;
    }
}
