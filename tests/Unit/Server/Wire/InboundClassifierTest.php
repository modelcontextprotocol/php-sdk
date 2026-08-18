<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Wire;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Wire\InboundClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The era decision one endpoint serving both lifecycles makes per request.
 *
 * Written as a truth table because that is what it is: the claim, the header
 * and the HTTP method are the only evidence, and every combination has to land
 * somewhere deliberate rather than by accident.
 */
final class InboundClassifierTest extends TestCase
{
    private const VERSION_HEADER = 'MCP-Protocol-Version';

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideLegacyTraffic')]
    #[TestDox('$_dataName is handshake-era traffic')]
    public function testClassifiesAsLegacy(string $method, ?string $body, array $headers = []): void
    {
        $classification = (new InboundClassifier())->classify($method, $body, $headers);

        $this->assertFalse($classification->isRejected(), 'expected a routing decision, got a rejection');
        $this->assertFalse($classification->modern);
    }

    /**
     * @return iterable<string, array{string, string|null, 2?: array<string, string>}>
     */
    public static function provideLegacyTraffic(): iterable
    {
        yield 'the initialize handshake' => ['POST', self::message('initialize')];

        yield 'a request with no envelope claim' => ['POST', self::message('tools/list')];

        yield 'a notification with no envelope claim' => ['POST', self::notification('notifications/initialized')];

        yield 'a GET, which the modern era has no use for' => ['GET', null];

        yield 'a DELETE ending a session' => ['DELETE', null];

        yield 'a claim naming a handshake revision' => [
            'POST',
            self::message('tools/list', ProtocolVersion::V2025_11_25->value),
            [self::VERSION_HEADER => ProtocolVersion::V2025_11_25->value],
        ];

        yield 'a header naming a handshake revision' => [
            'POST',
            self::message('tools/list'),
            [self::VERSION_HEADER => ProtocolVersion::V2025_11_25->value],
        ];

        // The endpoint's version middleware is what answers this, naming every
        // revision the endpoint serves. Routing it modern would answer with the
        // modern leg's shorter list instead.
        yield 'an unknown header with nothing in the body to back it' => [
            'POST',
            self::message('tools/list'),
            [self::VERSION_HEADER => '2030-01-01'],
        ];

        yield 'a batch of handshake-era messages' => [
            'POST',
            '['.self::message('tools/list').','.self::message('prompts/list').']',
        ];

        yield 'an empty body' => ['POST', ''];

        yield 'a body that is not JSON' => ['POST', 'not json at all'];

        yield 'a JSON body that is not an object' => ['POST', '"a string"'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideModernTraffic')]
    #[TestDox('$_dataName is modern-era traffic')]
    public function testClassifiesAsModern(string $body, array $headers, string $expectedVersion): void
    {
        $classification = (new InboundClassifier())->classify('POST', $body, $headers);

        $this->assertFalse($classification->isRejected(), 'expected a routing decision, got a rejection');
        $this->assertTrue($classification->modern);
        $this->assertSame($expectedVersion, $classification->claimedVersion);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function provideModernTraffic(): iterable
    {
        yield 'a request claiming the modern revision' => [
            self::message('tools/list', ProtocolVersion::V2026_07_28->value),
            [self::VERSION_HEADER => ProtocolVersion::V2026_07_28->value],
            ProtocolVersion::V2026_07_28->value,
        ];

        // The header is a cross-check, not the decision: a claim on its own is
        // enough evidence, and the leg it routes to is what says the header was
        // required.
        yield 'a claim with no header at all' => [
            self::message('server/discover', ProtocolVersion::V2026_07_28->value),
            [],
            ProtocolVersion::V2026_07_28->value,
        ];

        // Routed rather than refused so the answer can name what this endpoint
        // does serve, which only the modern leg knows.
        yield 'a claim naming a revision this SDK has never heard of' => [
            self::message('tools/list', '2099-01-01'),
            [self::VERSION_HEADER => '2099-01-01'],
            '2099-01-01',
        ];

        // `initialize` is handshake-era by definition — but a claim outranks the
        // method name, and the modern leg answers it with method-not-found.
        yield 'an initialize carrying a modern claim' => [
            self::message('initialize', ProtocolVersion::V2026_07_28->value),
            [self::VERSION_HEADER => ProtocolVersion::V2026_07_28->value],
            ProtocolVersion::V2026_07_28->value,
        ];

        // A notification has no claim of its own under this revision, so the
        // header is all the evidence there is.
        yield 'a notification under a modern header' => [
            self::notification('notifications/progress'),
            [self::VERSION_HEADER => ProtocolVersion::V2026_07_28->value],
            ProtocolVersion::V2026_07_28->value,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideRejectedTraffic')]
    #[TestDox('$_dataName is refused at the edge')]
    public function testRejects(string $body, array $headers, int $code, int $status): void
    {
        $classification = (new InboundClassifier())->classify('POST', $body, $headers);

        $this->assertTrue($classification->isRejected());
        $this->assertSame($code, $classification->error?->jsonSerialize()['error']['code']);
        $this->assertSame($status, $classification->httpStatus);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, int}>
     */
    public static function provideRejectedTraffic(): iterable
    {
        yield 'a header contradicting the claim' => [
            self::message('tools/list', ProtocolVersion::V2026_07_28->value),
            [self::VERSION_HEADER => ProtocolVersion::V2025_11_25->value],
            -32020,
            400,
        ];

        yield 'a handshake claim contradicted by a modern header' => [
            self::message('tools/list', ProtocolVersion::V2025_11_25->value),
            [self::VERSION_HEADER => ProtocolVersion::V2026_07_28->value],
            -32020,
            400,
        ];

        yield 'a modern header on a request carrying no envelope' => [
            self::message('tools/list'),
            [self::VERSION_HEADER => ProtocolVersion::V2026_07_28->value],
            -32602,
            400,
        ];

        yield 'a claim that is not a string' => [
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"'.RequestMeta::PROTOCOL_VERSION.'":42}}}',
            [],
            -32602,
            400,
        ];

        yield 'a batch holding a modern claim' => [
            '['.self::message('tools/list').','.self::message('prompts/list', ProtocolVersion::V2026_07_28->value).']',
            [],
            -32600,
            400,
        ];
    }

    #[TestDox('the header cross-check is case-insensitive, as HTTP field names are')]
    public function testHeaderLookupIgnoresCase(): void
    {
        $classification = (new InboundClassifier())->classify(
            'POST',
            self::message('tools/list', ProtocolVersion::V2026_07_28->value),
            ['mcp-protocol-version' => ProtocolVersion::V2025_11_25->value],
        );

        $this->assertTrue($classification->isRejected());
    }

    #[TestDox('an empty header value counts as no header, not as a contradiction')]
    public function testEmptyHeaderIsAbsent(): void
    {
        $classification = (new InboundClassifier())->classify(
            'POST',
            self::message('tools/list', ProtocolVersion::V2026_07_28->value),
            [self::VERSION_HEADER => ''],
        );

        $this->assertFalse($classification->isRejected());
        $this->assertTrue($classification->modern);
    }

    #[TestDox('the shared cross-check reports only a genuine disagreement')]
    public function testCrossCheckVersion(): void
    {
        $this->assertNull(InboundClassifier::crossCheckVersion(null, '2026-07-28'));
        $this->assertNull(InboundClassifier::crossCheckVersion('2026-07-28', '2026-07-28'));
        $this->assertNotNull(InboundClassifier::crossCheckVersion('2025-11-25', '2026-07-28'));
    }

    private static function message(string $method, ?string $claim = null): string
    {
        $params = null === $claim ? [] : ['_meta' => [
            RequestMeta::PROTOCOL_VERSION => $claim,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ]];

        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR);
    }

    private static function notification(string $method): string
    {
        return json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => []], \JSON_THROW_ON_ERROR);
    }
}
