<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Stateless;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class StatelessProtocolTest extends TestCase
{
    /**
     * @param array<string, mixed> $capabilities
     */
    private static function protocol(array $capabilities = []): StatelessProtocol
    {
        return Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addTool(static fn (): string => 'ok', name: 'plain_tool', description: 'Returns a fixed string')
            ->addTool(
                static function (RequestContext $context): string {
                    $gateway = $context->getClientGateway();

                    return implode(',', array_keys(array_filter([
                        'roots' => $gateway->supportsRoots(),
                        'sampling' => $gateway->supportsSampling(),
                        'sampling.tools' => $gateway->supportsSamplingTools(),
                        'elicitation' => $gateway->supportsElicitation(),
                        'elicitation.url' => $gateway->supportsElicitationUrl(),
                    ]))) ?: 'none';
                },
                name: 'probe_capabilities',
                description: 'Reports which client capabilities the gateway can see',
            )
            ->addResource(static fn (): string => 'body', 'test://static', 'static', 'A static resource')
            ->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private static function call(StatelessProtocol $protocol, string $method, array $params = [], array $extraHeaders = [], array $capabilities = []): array
    {
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => (object) $capabilities,
        ];

        return self::callWithHeaders($protocol, $method, $params, [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => $method,
            ...$extraHeaders,
        ]);
    }

    /**
     * The header-exact variant: nothing is filled in, so a test can leave a
     * required header out.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private static function callWithHeaders(
        StatelessProtocol $protocol,
        string $method,
        array $params = [],
        array $headers = [],
        ?string $metaVersion = null,
    ): array {
        $params['_meta'] ??= [
            RequestMeta::PROTOCOL_VERSION => $metaVersion ?? ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ];

        $result = $protocol->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR),
            $headers,
        );

        return [
            'status' => $result->httpStatus,
            'body' => json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    #[TestDox('the gateway sees the capabilities this request declared, not an empty session')]
    public function testClientCapabilitiesReachTheGateway(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'probe_capabilities', 'arguments' => []],
            ['Mcp-Name' => 'probe_capabilities'],
            ['roots' => new \stdClass(), 'elicitation' => ['url' => new \stdClass()], 'sampling' => ['tools' => new \stdClass()]],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame(
            'roots,sampling,sampling.tools,elicitation,elicitation.url',
            $answer['body']['result']['content'][0]['text'],
        );
    }

    #[TestDox('a client declaring nothing is reported as declaring nothing')]
    public function testEmptyCapabilitiesReportNone(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'probe_capabilities', 'arguments' => []],
            ['Mcp-Name' => 'probe_capabilities'],
        );

        $this->assertSame('none', $answer['body']['result']['content'][0]['text']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function removedMethods(): iterable
    {
        yield 'initialize' => ['initialize', []];
        yield 'ping' => ['ping', []];
        yield 'logging/setLevel' => ['logging/setLevel', ['level' => 'info']];
        yield 'resources/subscribe' => ['resources/subscribe', ['uri' => 'test://static']];
        yield 'resources/unsubscribe' => ['resources/unsubscribe', ['uri' => 'test://static']];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('removedMethods')]
    #[TestDox('a method this revision removed is answered as unknown')]
    public function testRemovedMethodsAreUnknown(string $method, array $params): void
    {
        $answer = self::call(self::protocol(), $method, $params);

        $this->assertSame(404, $answer['status']);
        $this->assertSame(Error::METHOD_NOT_FOUND, $answer['body']['error']['code']);
    }

    #[TestDox('a POST without the MCP-Protocol-Version header is refused')]
    public function testMissingProtocolVersionHeaderIsRefused(): void
    {
        $answer = self::callWithHeaders(
            self::protocol(),
            'tools/list',
            [],
            ['Mcp-Method' => 'tools/list'],
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::HEADER_MISMATCH, $answer['body']['error']['code']);
        $this->assertStringContainsString('MCP-Protocol-Version', $answer['body']['error']['message']);
    }

    #[TestDox('a header contradicting the _meta version outranks an unsupported version')]
    public function testContradictingProtocolVersionHeaderIsRefused(): void
    {
        $answer = self::callWithHeaders(
            self::protocol(),
            'tools/list',
            [],
            ['Mcp-Method' => 'tools/list', 'MCP-Protocol-Version' => '2025-11-25'],
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::HEADER_MISMATCH, $answer['body']['error']['code']);
    }

    #[TestDox('an unsupported version carries the supported set the client can retry from')]
    public function testUnsupportedProtocolVersionCarriesSupportedSet(): void
    {
        $answer = self::callWithHeaders(
            self::protocol(),
            'tools/list',
            [],
            ['Mcp-Method' => 'tools/list', 'MCP-Protocol-Version' => '1900-01-01'],
            '1900-01-01',
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::UNSUPPORTED_PROTOCOL_VERSION, $answer['body']['error']['code']);
        $this->assertSame('1900-01-01', $answer['body']['error']['data']['requested']);
        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answer['body']['error']['data']['supported']);
    }

    #[TestDox('elicitation without a named mode reports form, not url')]
    public function testElicitationDefaultsToFormMode(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'probe_capabilities', 'arguments' => []],
            ['Mcp-Name' => 'probe_capabilities'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame('elicitation', $answer['body']['result']['content'][0]['text']);
    }
}
