<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * One stdio server, either protocol era, decided by how the client opens.
 */
final class StdioDualEraTest extends TestCase
{
    #[TestDox('a client opening with a per-request envelope is served the modern era')]
    public function testModernOpening(): void
    {
        $answers = $this->serve(self::builder(), [
            self::modern(1, 'server/discover'),
            self::modern(2, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]),
        ]);

        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answers[1]['result']['supportedVersions']);
        $this->assertSame('test-server', $answers[1]['result']['_meta'][RequestMeta::SERVER_INFO]['name']);
        $this->assertSame('hi', $answers[2]['result']['content'][0]['text']);
    }

    #[TestDox('a client opening with the handshake is served the handshake era')]
    public function testHandshakeOpening(): void
    {
        $answers = $this->serve(self::builder(), [
            self::initialize(1),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']]],
        ]);

        $this->assertSame(ProtocolVersion::V2025_11_25->value, $answers[1]['result']['protocolVersion']);
        $this->assertSame('hi', $answers[2]['result']['content'][0]['text']);
    }

    #[TestDox('a handshake after a modern opening is refused, naming the modern revisions')]
    public function testHandshakeAfterModernOpeningIsRefused(): void
    {
        $answers = $this->serve(self::builder(), [
            self::modern(1, 'server/discover'),
            self::initialize(2),
        ]);

        $this->assertSame(Error::UNSUPPORTED_PROTOCOL_VERSION, $answers[2]['error']['code']);
        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answers[2]['error']['data']['supported']);
    }

    #[TestDox('a modern request after a handshake opening is refused')]
    public function testModernRequestAfterHandshakeOpeningIsRefused(): void
    {
        $answers = $this->serve(self::builder(), [
            self::initialize(1),
            self::modern(2, 'server/discover'),
        ]);

        $this->assertSame(ProtocolVersion::V2025_11_25->value, $answers[1]['result']['protocolVersion']);
        $this->assertSame(Error::INVALID_REQUEST, $answers[2]['error']['code']);
    }

    #[TestDox('a handshake-only server refuses a modern probe and still accepts the handshake')]
    public function testHandshakeOnlyServerRefusesTheProbe(): void
    {
        $answers = $this->serve(self::builder()->withoutModernEra(), [
            self::modern(1, 'server/discover'),
            self::initialize(2),
        ]);

        $this->assertSame(Error::UNSUPPORTED_PROTOCOL_VERSION, $answers[1]['error']['code']);
        $this->assertNotContains(ProtocolVersion::V2026_07_28->value, $answers[1]['error']['data']['supported']);
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $answers[2]['result']['protocolVersion']);
    }

    #[TestDox('a probe without an envelope gets an error it can correlate, not silence')]
    public function testUnenvelopedRequestBeforeTheHandshakeIsAnswered(): void
    {
        $answers = $this->serve(self::builder(), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover'],
        ]);

        $this->assertSame(Error::INVALID_REQUEST, $answers[1]['error']['code']);
    }

    #[TestDox('a modern request streams its progress on the shared channel before its result')]
    public function testModernProgressIsStreamed(): void
    {
        $lines = $this->exchange(self::builder(), [
            self::modern(1, 'tools/call', ['name' => 'count', 'arguments' => [], '_meta' => ['progressToken' => 'p']]),
        ]);

        $this->assertSame(['notifications/progress', 'notifications/progress'], [$lines[0]['method'], $lines[1]['method']]);
        $this->assertSame(1, $lines[2]['id']);
        $this->assertSame('counted', $lines[2]['result']['content'][0]['text']);
    }

    private static function builder(): Builder
    {
        return Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addTool(static fn (string $text): string => $text, name: 'echo', description: 'Echoes')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->progress(1, 2);
                $context->getClientGateway()->progress(2, 2);

                return 'counted';
            }, name: 'count', description: 'Reports progress');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function modern(int $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
            ...($params['_meta'] ?? []),
        ];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    /**
     * @return array<string, mixed>
     */
    private static function initialize(int $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => [
            'protocolVersion' => ProtocolVersion::V2025_11_25->value,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
        ]];
    }

    /**
     * Runs the server over the given input until it is exhausted, and returns
     * every answer by the id it answers.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function serve(Builder $builder, array $messages): array
    {
        $answers = [];

        foreach ($this->exchange($builder, $messages) as $line) {
            if (\array_key_exists('id', $line)) {
                $answers[$line['id']] = $line;
            }
        }

        return $answers;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function exchange(Builder $builder, array $messages): array
    {
        $input = fopen('php://temp', 'r+');
        // A file rather than memory: running the server closes its streams.
        $outputFile = tempnam(sys_get_temp_dir(), 'mcp-stdio');
        $output = fopen($outputFile, 'w');

        foreach ($messages as $message) {
            fwrite($input, json_encode($message, \JSON_THROW_ON_ERROR)."\n");
        }

        rewind($input);

        $builder->build()->run(new StdioTransport($input, $output));

        $written = (string) file_get_contents($outputFile);
        unlink($outputFile);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $written))),
        );
    }
}
