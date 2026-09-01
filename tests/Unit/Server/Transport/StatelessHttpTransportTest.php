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
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Tests\Unit\Server\Transport\Fixture\ShortReadStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class StatelessHttpTransportTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function protocol(): StatelessProtocol
    {
        return Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addTool(static fn (string $text = ''): string => 'echo:'.$text, name: 'echo_tool', description: 'Echoes its argument')
            ->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    /**
     * A `tools/call` body padded so it comfortably exceeds one 8 KiB read.
     */
    private function callBody(int $padding = 0): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo_tool',
                'arguments' => ['text' => str_repeat('x', $padding)],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    private function post(string $body): ServerRequestInterface
    {
        return $this->factory
            ->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Host', 'localhost')
            ->withHeader('MCP-Protocol-Version', ProtocolVersion::V2026_07_28->value)
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'echo_tool')
            ->withBody($this->factory->createStream($body));
    }

    #[TestDox('a body arriving in short reads is assembled whole, not truncated')]
    public function testShortReadsDoNotTruncateTheBody(): void
    {
        $body = $this->callBody(20_000);

        // PSR-7 promises only *up to* the requested length; a stream over
        // php://input or a chunked transfer routinely returns less.
        $request = $this->post('')->withBody(new ShortReadStream($body, 64));

        $response = (new StatelessHttpTransport($this->protocol(), $this->factory, $this->factory))->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $decoded = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('echo:'.str_repeat('x', 20_000), $decoded['result']['content'][0]['text']);
    }

    #[TestDox('a body over the cap is refused with 413')]
    public function testOversizedBodyIsRefused(): void
    {
        $request = $this->post($this->callBody(2048));

        $response = (new StatelessHttpTransport($this->protocol(), $this->factory, $this->factory, maxBodyBytes: 256))->handle($request);

        $this->assertSame(413, $response->getStatusCode());
    }

    #[TestDox('an oversized body is refused even when its size is not advertised')]
    public function testOversizedUnsizedBodyIsRefused(): void
    {
        $request = $this->post('')->withBody(new ShortReadStream($this->callBody(2048), 64, advertiseSize: false));

        $response = (new StatelessHttpTransport($this->protocol(), $this->factory, $this->factory, maxBodyBytes: 256))->handle($request);

        $this->assertSame(413, $response->getStatusCode());
    }

    #[TestDox('a notification POST is acknowledged with 202 and no body')]
    public function testNotificationPostIsAccepted(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/something',
            'params' => [],
        ], \JSON_THROW_ON_ERROR);

        $request = $this->post($body)->withHeader('Mcp-Method', 'notifications/something');

        $response = (new StatelessHttpTransport($this->protocol(), $this->factory, $this->factory))->handle($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    #[TestDox('GET and DELETE are refused: there is no session to address')]
    public function testOnlyPostIsAccepted(): void
    {
        $transport = new StatelessHttpTransport($this->protocol(), $this->factory, $this->factory);

        foreach (['GET', 'DELETE', 'PUT'] as $method) {
            $request = $this->factory
                ->createServerRequest($method, 'http://localhost/mcp')
                ->withHeader('Host', 'localhost');

            $this->assertSame(405, $transport->handle($request)->getStatusCode(), $method);
        }
    }
}
