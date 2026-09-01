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
use Mcp\Schema\RequestMeta;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * One endpoint, both protocol eras.
 *
 * The same server object, the same transport class and the same URL answer a
 * handshake-era client and a modern-era one — which is the whole point of
 * classifying the request instead of asking the client to pick an endpoint.
 */
final class DualEraRoutingTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[TestDox('the initialize handshake is answered on the endpoint that also serves the modern era')]
    public function testHandshakeStillWorks(): void
    {
        $answer = $this->post($this->server(), $this->handshake());

        $this->assertSame(200, $answer['status']);
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $answer['body']['result']['protocolVersion']);
    }

    #[TestDox('server/discover is answered on that same endpoint, with no handshake before it')]
    public function testDiscoverOnTheSameEndpoint(): void
    {
        $answer = $this->post($this->server(), $this->enveloped('server/discover'), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'server/discover',
        ]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answer['body']['result']['supportedVersions']);
    }

    #[TestDox('one tool answers both eras, from one registry')]
    public function testOneToolServesBothEras(): void
    {
        $server = $this->server();

        $handshake = $this->post($server, $this->handshake());
        $session = $handshake['session'];
        $this->assertNotSame('', $session, 'the handshake leg still mints a session');

        $legacy = $this->post($server, json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'echo_tool', 'arguments' => ['text' => 'legacy']],
        ], \JSON_THROW_ON_ERROR), [
            'Mcp-Session-Id' => $session,
            'MCP-Protocol-Version' => ProtocolVersion::V2025_11_25->value,
        ]);

        $modern = $this->post($server, $this->enveloped('tools/call', [
            'name' => 'echo_tool',
            'arguments' => ['text' => 'modern'],
        ]), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'echo_tool',
        ]);

        $this->assertSame('echo:legacy', $legacy['body']['result']['content'][0]['text']);
        $this->assertSame('echo:modern', $modern['body']['result']['content'][0]['text']);

        // The modern answer carries the wire fields its revision adds; the
        // handshake one does not. Same handler, two codecs.
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
    }

    #[TestDox('a modern claim contradicted by the header is refused before either leg sees it')]
    public function testHeaderContradictingTheClaimIsRefused(): void
    {
        $answer = $this->post($this->server(), $this->enveloped('tools/list'), [
            'MCP-Protocol-Version' => ProtocolVersion::V2025_11_25->value,
        ]);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32020, $answer['body']['error']['code']);
    }

    #[TestDox('a modern header the body does not back up is refused, naming the member it wants')]
    public function testModernHeaderWithoutAnEnvelopeIsRefused(): void
    {
        $answer = $this->post($this->server(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ], \JSON_THROW_ON_ERROR), ['MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value]);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32602, $answer['body']['error']['code']);
        $this->assertStringContainsString(RequestMeta::PROTOCOL_VERSION, $answer['body']['error']['message']);
    }

    #[TestDox('the version middleware lets a modern header through instead of turning it away')]
    public function testVersionMiddlewareAcceptsModernRevisions(): void
    {
        $answer = $this->post($this->server(), $this->enveloped('server/discover'), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'server/discover',
        ]);

        $this->assertSame(200, $answer['status']);
    }

    #[TestDox('an unknown header with no claim behind it is refused by the handshake leg, offering its revisions')]
    public function testUnknownVersionHeaderIsRefused(): void
    {
        $answer = $this->post($this->server(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], \JSON_THROW_ON_ERROR), ['MCP-Protocol-Version' => '2030-01-01']);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32022, $answer['body']['error']['code']);
        $this->assertSame(
            array_map(static fn (ProtocolVersion $v): string => $v->value, ProtocolVersion::handshakeVersions()),
            $answer['body']['error']['data']['supported'],
        );
    }

    #[TestDox('an unknown revision claimed in the envelope is answered by the modern leg, offering its own')]
    public function testUnknownClaimReachesTheModernLeg(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                RequestMeta::PROTOCOL_VERSION => '2099-01-01',
                RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
            ]],
        ], \JSON_THROW_ON_ERROR);

        $answer = $this->post($this->server(), $body, ['MCP-Protocol-Version' => '2099-01-01']);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32022, $answer['body']['error']['code']);
        // The claim named the envelope mechanism, so the answer names the
        // revisions that mechanism has — not the handshake ones it cannot use.
        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answer['body']['error']['data']['supported']);
    }

    #[TestDox('a server built without the modern era refuses a modern claim, naming what it does serve')]
    public function testHandshakeOnlyServerRefusesModernTraffic(): void
    {
        $answer = $this->post($this->server(modern: false), $this->enveloped('server/discover'), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'server/discover',
        ]);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32022, $answer['body']['error']['code']);
        $this->assertNotContains(ProtocolVersion::V2026_07_28->value, $answer['body']['error']['data']['supported']);
    }

    #[TestDox('a DELETE still ends a handshake-era session')]
    public function testDeleteReachesTheHandshakeLeg(): void
    {
        $server = $this->server();
        $session = $this->post($server, $this->handshake())['session'];

        $request = $this->factory->createServerRequest('DELETE', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Mcp-Session-Id', $session);

        $response = $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function server(bool $modern = true): Server
    {
        $builder = Server::builder()
            ->setServerInfo('dual-era-server', '1.0.0')
            ->addTool(static fn (string $text = ''): string => 'echo:'.$text, name: 'echo_tool', description: 'Echoes its argument');

        if (!$modern) {
            $builder->withoutModernEra();
        }

        return $builder->build();
    }

    private function handshake(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'handshake-client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function enveloped(string $method, array $params = []): string
    {
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ];

        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, session: string, body: array<string, mixed>}
     */
    private function post(Server $server, string $body, array $headers = []): array
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));

        return [
            'status' => $response->getStatusCode(),
            'session' => $response->getHeaderLine('Mcp-Session-Id'),
            'body' => self::decode($response),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $payload = (string) $response->getBody();

        if ('' === $payload) {
            return [];
        }

        return json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
    }
}
