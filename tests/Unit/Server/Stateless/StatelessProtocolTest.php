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

use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Stateless\StatelessResult;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Tests\Unit\Server\Extension\ThingExtension;
use Mcp\Tests\Unit\Server\Extension\UnservedThingExtension;
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
            ->addTool(
                static function (RequestContext $context): string {
                    $gateway = $context->getClientGateway();
                    $gateway->progress(0, 100, 'starting');
                    $gateway->progress(100, 100, 'done');

                    return 'progressed';
                },
                name: 'progress_tool',
                description: 'Reports progress while it works',
            )
            ->addTool(
                static function (RequestContext $context): string {
                    $gateway = $context->getClientGateway();
                    foreach ([LoggingLevel::Debug, LoggingLevel::Info, LoggingLevel::Warning, LoggingLevel::Error] as $level) {
                        $gateway->log($level, $level->value.' message');
                    }

                    return 'logged';
                },
                name: 'logging_tool',
                description: 'Emits one message at each of four levels',
            )
            ->addTool(
                static function (): never {
                    throw new MissingRequiredClientCapabilityException(new ClientCapabilities(roots: false, sampling: true), 'needs sampling');
                },
                name: 'capability_tool',
                description: 'Always reports a missing client capability',
            )
            ->addTool(
                static function (RequestContext $context): string {
                    $answer = $context->getClientGateway()->elicit('name?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));

                    return 'hello '.($answer->content['n'] ?? '?');
                },
                name: 'elicits_directly',
                description: 'Asks through the gateway, the way a handshake-era handler does',
            )
            ->addTool(
                static function (RequestContext $context): string {
                    $gateway = $context->getClientGateway();
                    $gateway->progress(0, 100, 'starting');

                    $answer = $gateway->elicit('name?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));

                    return 'hello '.($answer->content['n'] ?? '?');
                },
                name: 'elicits_after_progress',
                description: 'Reports progress, then asks through the gateway',
            )
            ->addTool(
                static function (RequestContext $context): string {
                    // A kind this revision removed rather than reshaped: kept as
                    // a fixture so the refusal has something to refuse.
                    $context->getClientGateway()->request(new ListRootsRequest());

                    return 'unreachable';
                },
                name: 'asks_for_roots',
                description: 'Asks the client directly for something this revision removed',
            )
            ->addTool(
                static fn (): InputRequiredResult => new InputRequiredResult([
                    'consent' => ElicitRequest::forUrl('Approve out of band', 'https://example.com/consent'),
                ]),
                name: 'asks_by_url',
                description: 'Asks through a url-mode elicitation',
            )
            ->addTool(
                static function (RequestContext $context): InputRequiredResult {
                    $context->getClientGateway()->progress(0, 100, 'starting');

                    return new InputRequiredResult([
                        'consent' => ElicitRequest::forUrl('Approve out of band', 'https://example.com/consent'),
                    ]);
                },
                name: 'asks_by_url_after_progress',
                description: 'Emits progress, then asks through a url-mode elicitation',
            )
            ->addTool(
                static function (RequestContext $context): string {
                    $pairs = [];
                    foreach ($context->getTraceContext() as $key => $value) {
                        $pairs[] = $key.'='.$value;
                    }

                    return implode(';', $pairs) ?: 'none';
                },
                name: 'probe_trace',
                description: 'Reports the trace context it was called with',
            )
            ->addResource(static fn (): string => 'body', 'test://static', 'static', 'A static resource')
            ->addResource(
                static function (RequestContext $context): string|InputRequiredResult {
                    $input = $context->getInputContext();

                    if (null === $input || !$input->has('who')) {
                        return new InputRequiredResult([
                            'who' => new ElicitRequest('Who is asking?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n'])),
                        ]);
                    }

                    return 'body for '.($input->response('who')['content']['n'] ?? '?');
                },
                'test://gated',
                'gated',
                'A resource that asks who is reading before it answers',
            )
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
        // Merged, not replaced: a test may add its own `_meta` members.
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => (object) $capabilities,
            ...($params['_meta'] ?? []),
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

    /**
     * Drains a streaming result into the frames it would write.
     *
     * @return list<array<string, mixed>>
     */
    private static function frames(StatelessResult $result): array
    {
        $frames = [];
        $stream = $result->frames;
        self::assertNotNull($stream);

        foreach ($stream() as $frame) {
            if (null !== $frame) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function callStreaming(StatelessProtocol $protocol, string $tool, array $meta = []): StatelessResult
    {
        return $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => [],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                    ...$meta,
                ],
            ],
        ], \JSON_THROW_ON_ERROR), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $tool,
            'Accept' => 'application/json, text/event-stream',
        ]);
    }

    #[TestDox('progress notifications reach the client on the response stream')]
    public function testProgressStreamsBeforeTheResponse(): void
    {
        $result = self::callStreaming(self::protocol(), 'progress_tool', ['progressToken' => 'tok-1']);

        $this->assertTrue($result->isStream());

        $frames = self::frames($result);

        $this->assertCount(3, $frames);
        $this->assertSame('notifications/progress', $frames[0]['method']);
        $this->assertSame('tok-1', $frames[0]['params']['progressToken']);
        $this->assertSame('notifications/progress', $frames[1]['method']);
        $this->assertSame(7, $frames[2]['id']);
        $this->assertSame('complete', $frames[2]['result']['resultType']);
    }

    #[TestDox('without a progress token the handler emits nothing and gets a plain response')]
    public function testProgressWithoutATokenIsNotStreamed(): void
    {
        $result = self::callStreaming(self::protocol(), 'progress_tool');

        $this->assertFalse($result->isStream());
        $this->assertSame(200, $result->httpStatus);
    }

    #[TestDox('a client that will not read a stream gets its notifications dropped, not a stream')]
    public function testNotificationsAreDroppedWithoutAnAcceptingClient(): void
    {
        $result = self::protocol()->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'progress_tool',
                'arguments' => [],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                    'progressToken' => 'tok-1',
                ],
            ],
        ], \JSON_THROW_ON_ERROR), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'progress_tool',
            'Accept' => 'application/json',
        ]);

        $this->assertFalse($result->isStream());
        $this->assertSame(200, $result->httpStatus);
    }

    #[TestDox('a request naming no log level receives no log notifications')]
    public function testLoggingIsSilentWithoutARequestedLevel(): void
    {
        $result = self::callStreaming(self::protocol(), 'logging_tool');

        $this->assertFalse($result->isStream());
    }

    #[TestDox('a request naming a log level receives the messages at or above it')]
    public function testLoggingHonoursTheRequestedLevel(): void
    {
        $frames = self::frames(self::callStreaming(self::protocol(), 'logging_tool', [RequestMeta::LOG_LEVEL => 'warning']));

        $levels = [];
        foreach ($frames as $frame) {
            if ('notifications/message' === ($frame['method'] ?? null)) {
                $levels[] = $frame['params']['level'];
            }
        }

        // The tool emits debug, info, warning and error.
        $this->assertSame(['warning', 'error'], $levels);
    }

    #[TestDox('a lower requested level lets more through')]
    public function testLoggingAtDebugLetsEverythingThrough(): void
    {
        $frames = self::frames(self::callStreaming(self::protocol(), 'logging_tool', [RequestMeta::LOG_LEVEL => 'debug']));

        $levels = [];
        foreach ($frames as $frame) {
            if ('notifications/message' === ($frame['method'] ?? null)) {
                $levels[] = $frame['params']['level'];
            }
        }

        $this->assertSame(['debug', 'info', 'warning', 'error'], $levels);
    }

    #[TestDox('an error raised before any notification keeps its own status')]
    public function testEarlyFailureIsStillAStatusCode(): void
    {
        $result = self::callStreaming(self::protocol(), 'capability_tool');

        $this->assertFalse($result->isStream());
        $this->assertSame(400, $result->httpStatus);

        $body = json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $body['error']['code']);
    }

    #[TestDox('a server-initiated request this revision removed is refused')]
    public function testServerInitiatedRequestIsRefused(): void
    {
        $result = self::callStreaming(self::protocol(), 'asks_for_roots');

        $body = json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(500, $result->httpStatus);
        $this->assertStringContainsString('no server-initiated requests', $body['error']['message']);
    }

    #[TestDox('a gateway elicitation becomes the ask this revision carries in the result')]
    public function testGatewayElicitationBecomesAnAsk(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'elicits_directly', 'arguments' => []],
            ['Mcp-Name' => 'elicits_directly'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame('input_required', $answer['body']['result']['resultType']);
        // Unnamed, so filed under its position among the handler's asks.
        $this->assertSame('elicitation/create', $answer['body']['result']['inputRequests']['elicitation_1']['method']);
        // Nothing is answered yet, so there is nothing to carry.
        $this->assertArrayNotHasKey('requestState', $answer['body']['result']);
    }

    #[TestDox('the retry resumes the gateway call the ask came from')]
    public function testGatewayElicitationResumesOnRetry(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            [
                'name' => 'elicits_directly',
                'arguments' => [],
                'inputResponses' => ['elicitation_1' => ['action' => 'accept', 'content' => ['n' => 'ada']]],
            ],
            ['Mcp-Name' => 'elicits_directly'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame('hello ada', $answer['body']['result']['content'][0]['text']);
    }

    #[TestDox('a gateway ask that follows a notification ends the stream it opened')]
    public function testGatewayElicitationEndsAnOpenStream(): void
    {
        $result = self::callStreaming(self::protocol(), 'elicits_after_progress', [
            'progressToken' => 'p1',
            RequestMeta::CLIENT_CAPABILITIES => ['elicitation' => new \stdClass()],
        ]);

        $this->assertTrue($result->isStream());

        $frames = self::frames($result);
        $this->assertSame('notifications/progress', $frames[0]['method']);
        $this->assertSame('input_required', $frames[1]['result']['resultType']);
    }

    #[TestDox('an ask the client did not declare it can answer is refused before it is minted')]
    public function testUndeclaredGatewayElicitationIsRefused(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'elicits_directly', 'arguments' => []],
            ['Mcp-Name' => 'elicits_directly'],
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $answer['body']['error']['code']);
    }

    /**
     * A handler asking twice: the second round has to remember the first
     * round's answer, which is what the `requestState` is for.
     */
    private static function twoAskProtocol(bool $signed = true): StatelessProtocol
    {
        $builder = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addTool(
                static function (RequestContext $context): string {
                    $gateway = $context->getClientGateway();
                    $schema = new ElicitationSchema(['v' => new StringSchemaDefinition('V')], ['v']);

                    $when = $gateway->elicit('When?', $schema, key: 'when');
                    $seat = $gateway->elicit('Seat?', $schema, key: 'seat');

                    return ($when->content['v'] ?? '').'/'.($seat->content['v'] ?? '');
                },
                name: 'books_flight',
                description: 'Asks twice before it answers',
            );

        if ($signed) {
            $builder->setRequestState(str_repeat('k', 32));
        }

        return $builder->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function book(StatelessProtocol $protocol, array $params = []): array
    {
        return self::call(
            $protocol,
            'tools/call',
            ['name' => 'books_flight', 'arguments' => [], ...$params],
            ['Mcp-Name' => 'books_flight'],
            ['elicitation' => new \stdClass()],
        );
    }

    #[TestDox('a handler asking twice is served one ask per round, remembering the first')]
    public function testNamedAsksCarryAcrossRounds(): void
    {
        $protocol = self::twoAskProtocol();

        $first = self::book($protocol);
        $this->assertSame('input_required', $first['body']['result']['resultType']);
        $this->assertSame(['when'], array_keys($first['body']['result']['inputRequests']));
        // Nothing answered yet, so nothing to carry.
        $this->assertArrayNotHasKey('requestState', $first['body']['result']);

        $second = self::book($protocol, [
            'inputResponses' => ['when' => ['action' => 'accept', 'content' => ['v' => 'morning']]],
        ]);
        $this->assertSame('input_required', $second['body']['result']['resultType']);
        $this->assertSame(['seat'], array_keys($second['body']['result']['inputRequests']));
        $this->assertIsString($second['body']['result']['requestState']);

        // The client echoes the state and answers only what it was just asked.
        $third = self::book($protocol, [
            'inputResponses' => ['seat' => ['action' => 'accept', 'content' => ['v' => 'aisle']]],
            'requestState' => $second['body']['result']['requestState'],
        ]);
        $this->assertSame(200, $third['status']);
        $this->assertSame('morning/aisle', $third['body']['result']['content'][0]['text']);
    }

    #[TestDox('carrying an answer to the next round without a signing key fails loudly')]
    public function testCarryingAnAnswerNeedsASigningKey(): void
    {
        $answer = self::book(self::twoAskProtocol(signed: false), [
            'inputResponses' => ['when' => ['action' => 'accept', 'content' => ['v' => 'morning']]],
        ]);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::INTERNAL_ERROR, $answer['body']['error']['code']);
    }

    #[TestDox('an answer that does not parse is asked for again')]
    public function testMalformedAnswerIsAskedAgain(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            [
                'name' => 'elicits_directly',
                'arguments' => [],
                // Accepted, but a form-mode acceptance carries content.
                'inputResponses' => ['elicitation_1' => ['action' => 'accept']],
            ],
            ['Mcp-Name' => 'elicits_directly'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame('input_required', $answer['body']['result']['resultType']);
        $this->assertArrayHasKey('elicitation_1', $answer['body']['result']['inputRequests']);
    }

    #[TestDox('a request\'s trace context reaches the handler')]
    public function testTraceContextReachesTheHandler(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            [
                'name' => 'probe_trace',
                'arguments' => [],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                    'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
                    'baggage' => 'tenant=acme',
                ],
            ],
            ['Mcp-Name' => 'probe_trace'],
        );

        $this->assertSame(
            'traceparent=00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01;baggage=tenant=acme',
            $answer['body']['result']['content'][0]['text'],
        );
    }

    #[TestDox('a native traceparent/tracestate header reaches the handler when `_meta` carries none')]
    public function testHttpTraceHeadersReachTheHandler(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'probe_trace', 'arguments' => []],
            [
                'Mcp-Name' => 'probe_trace',
                'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
                'tracestate' => 'acme=1',
            ],
        );

        $this->assertSame(
            'traceparent=00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01;tracestate=acme=1',
            $answer['body']['result']['content'][0]['text'],
        );
    }

    #[TestDox('`_meta` trace context wins over a conflicting native header')]
    public function testMetaTraceContextWinsOverHeader(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            [
                'name' => 'probe_trace',
                'arguments' => [],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                    'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                ],
            ],
            [
                'Mcp-Name' => 'probe_trace',
                'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
            ],
        );

        $this->assertSame(
            'traceparent=00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
            $answer['body']['result']['content'][0]['text'],
        );
    }

    #[TestDox('notifications caused by a traced request carry its trace context')]
    public function testNotificationsCarryTheTraceContext(): void
    {
        $result = self::callStreaming(self::protocol(), 'progress_tool', [
            'progressToken' => 'tok-1',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
        ]);

        $frames = self::frames($result);

        $this->assertSame(
            '00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01',
            $frames[0]['params']['_meta']['traceparent'],
        );
    }

    #[TestDox('an untraced request adds no trace metadata')]
    public function testUntracedRequestAddsNothing(): void
    {
        $frames = self::frames(self::callStreaming(self::protocol(), 'progress_tool', ['progressToken' => 'tok-1']));

        $this->assertArrayNotHasKey('traceparent', $frames[0]['params']['_meta'] ?? []);
    }

    #[TestDox('a missing resource answers -32602 with the uri, not the retired -32002')]
    public function testResourceNotFoundUsesInvalidParams(): void
    {
        $answer = self::call(
            self::protocol(),
            'resources/read',
            ['uri' => 'test://absent'],
            ['Mcp-Name' => 'test://absent'],
        );

        $this->assertSame(Error::INVALID_PARAMS, $answer['body']['error']['code']);
        $this->assertSame('test://absent', $answer['body']['error']['data']['uri']);
    }

    #[TestDox('an unknown tool is a bad parameter, not an unknown method')]
    public function testUnknownToolUsesInvalidParams(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'no_such_tool', 'arguments' => []],
            ['Mcp-Name' => 'no_such_tool'],
        );

        $this->assertSame(Error::INVALID_PARAMS, $answer['body']['error']['code']);
    }

    #[TestDox('an unknown prompt is a bad parameter too')]
    public function testUnknownPromptUsesInvalidParams(): void
    {
        $answer = self::call(
            self::protocol(),
            'prompts/get',
            ['name' => 'no_such_prompt', 'arguments' => []],
            ['Mcp-Name' => 'no_such_prompt'],
        );

        $this->assertSame(Error::INVALID_PARAMS, $answer['body']['error']['code']);
    }

    #[TestDox('this revision never emits the codes it reserved')]
    public function testReservedCodesAreNeverEmitted(): void
    {
        $protocol = self::protocol();

        $answers = [
            self::call($protocol, 'resources/read', ['uri' => 'test://absent'], ['Mcp-Name' => 'test://absent']),
            self::call($protocol, 'tools/call', ['name' => 'nope', 'arguments' => []], ['Mcp-Name' => 'nope']),
            self::call($protocol, 'prompts/get', ['name' => 'nope', 'arguments' => []], ['Mcp-Name' => 'nope']),
        ];

        foreach ($answers as $answer) {
            // -32002 (resource not found) and -32042 (url elicitation required)
            // are reserved by earlier revisions and never reused.
            $this->assertNotContains($answer['body']['error']['code'], [-32002, -32042]);
        }
    }

    #[TestDox('resources/read can ask for input before it answers')]
    public function testResourceReadCanAskForInput(): void
    {
        $answer = self::call(
            self::protocol(),
            'resources/read',
            ['uri' => 'test://gated'],
            ['Mcp-Name' => 'test://gated'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame('input_required', $answer['body']['result']['resultType']);
        $this->assertArrayHasKey('who', $answer['body']['result']['inputRequests']);

        // Interim results are not cacheable and carry no hints.
        $this->assertArrayNotHasKey('ttlMs', $answer['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $answer['body']['result']);
    }

    #[TestDox('an ask the client cannot answer is refused with -32021, not sent')]
    public function testUndeclaredInputRequestIsRefused(): void
    {
        // The client declared nothing, so it has no way to answer an
        // elicitation — and a retry carrying one could never arrive.
        $answer = self::call(
            self::protocol(),
            'resources/read',
            ['uri' => 'test://gated'],
            ['Mcp-Name' => 'test://gated'],
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $answer['body']['error']['code']);
        $this->assertArrayHasKey('elicitation', $answer['body']['error']['data']['requiredCapabilities']);
    }

    #[TestDox('url-mode elicitation needs its own declaration, which form does not satisfy')]
    public function testUrlElicitationNeedsItsOwnDeclaration(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'asks_by_url', 'arguments' => []],
            ['Mcp-Name' => 'asks_by_url'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $answer['body']['error']['code']);
        $this->assertArrayHasKey('url', $answer['body']['error']['data']['requiredCapabilities']['elicitation']);
    }

    #[TestDox('a client declaring url mode gets the ask')]
    public function testUrlElicitationPassesWhenDeclared(): void
    {
        $answer = self::call(
            self::protocol(),
            'tools/call',
            ['name' => 'asks_by_url', 'arguments' => []],
            ['Mcp-Name' => 'asks_by_url'],
            ['elicitation' => ['url' => new \stdClass()]],
        );

        $this->assertSame(200, $answer['status']);
        $this->assertSame('input_required', $answer['body']['result']['resultType']);
    }

    #[TestDox('an ask the client cannot answer is refused with -32021 even mid-stream')]
    public function testUndeclaredInputRequestIsRefusedWhenStreamed(): void
    {
        // The handler emits progress before its InputRequiredResult, so the
        // answer goes out on the response stream, not a plain 400 — the gate
        // still has to apply there.
        $result = self::callStreaming(self::protocol(), 'asks_by_url_after_progress', ['progressToken' => 'tok-1']);

        $this->assertTrue($result->isStream());

        $frames = self::frames($result);
        $this->assertNotEmpty($frames);
        $last = json_decode(json_encode($frames[array_key_last($frames)], \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $last['error']['code']);
        $this->assertArrayHasKey('url', $last['error']['data']['requiredCapabilities']['elicitation']);
    }

    #[TestDox('a resource can set its own freshness, overriding the policy')]
    public function testResourceAuthoredCacheHintsWin(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->setCachePolicy(CachePolicy::default(60_000, CacheScope::Public))
            ->addResource(
                static fn (): ReadResourceResult => new ReadResourceResult(
                    [new TextResourceContents('test://volatile', 'text/plain', 'now')],
                    ttlMs: 250,
                    cacheScope: CacheScope::Private,
                ),
                'test://volatile',
                'volatile',
                'A resource that decides its own freshness',
            )
            ->addResource(static fn (): string => 'body', 'test://plain', 'plain', 'A resource that defers to policy')
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $volatile = self::call($protocol, 'resources/read', ['uri' => 'test://volatile'], ['Mcp-Name' => 'test://volatile']);
        $this->assertSame(250, $volatile['body']['result']['ttlMs']);
        $this->assertSame('private', $volatile['body']['result']['cacheScope']);

        $plain = self::call($protocol, 'resources/read', ['uri' => 'test://plain'], ['Mcp-Name' => 'test://plain']);
        $this->assertSame(60_000, $plain['body']['result']['ttlMs']);
        $this->assertSame('public', $plain['body']['result']['cacheScope']);
    }

    #[TestDox('a first-round read carries caching hints')]
    public function testFirstRoundReadIsCacheable(): void
    {
        $answer = self::call(
            self::protocol(),
            'resources/read',
            ['uri' => 'test://static'],
            ['Mcp-Name' => 'test://static'],
        );

        $this->assertArrayHasKey('ttlMs', $answer['body']['result']);
        $this->assertArrayHasKey('cacheScope', $answer['body']['result']);
    }

    #[TestDox('a result produced by a multi round-trip retry carries no caching hints')]
    public function testMrtrRetryResultIsNotCacheable(): void
    {
        $answer = self::call(
            self::protocol(),
            'resources/read',
            [
                'uri' => 'test://gated',
                'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['n' => 'ada']]],
            ],
            ['Mcp-Name' => 'test://gated'],
            ['elicitation' => new \stdClass()],
        );

        $this->assertSame('complete', $answer['body']['result']['resultType']);
        // The inputs are not part of any cache key, so the answer must not be
        // presented as reusable.
        $this->assertArrayNotHasKey('ttlMs', $answer['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $answer['body']['result']);
    }

    #[TestDox('a listen stream acknowledges, then carries what the client asked for')]
    public function testListenStreamDeliversSubscribedNotifications(): void
    {
        $bus = new InMemoryNotificationBus();

        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->setCapabilities(new ServerCapabilities(toolsListChanged: true, resourcesListChanged: true, resourcesSubscribe: true))
            ->setNotificationBus($bus)
            ->setSubscriptionLifetime(0.8)
            ->addTool(static fn (): string => 'ok', name: 'a_tool', description: 'x')
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $result = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'subscriptions/listen',
            'params' => [
                'notifications' => ['toolsListChanged' => true, 'resourceSubscriptions' => ['file:///watched']],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'subscriptions/listen',
        ]);

        $this->assertTrue($result->isStream());

        $frames = [];
        $published = false;
        $stream = $result->frames;
        $this->assertNotNull($stream);

        foreach ($stream() as $frame) {
            if (null === $frame) {
                // Publish once the stream is established, so the notifications
                // arrive the way a concurrent request would deliver them.
                if (!$published) {
                    $bus->publish(new ToolListChangedNotification());
                    $bus->publish(new PromptListChangedNotification());
                    $bus->publish(new ResourceUpdatedNotification('file:///watched'));
                    $bus->publish(new ResourceUpdatedNotification('file:///ignored'));
                    $published = true;
                }

                continue;
            }

            $frames[] = $frame;
        }

        $methods = array_map(static fn (array $frame): string => $frame['method'] ?? 'response', $frames);

        // The acknowledgment MUST come first, the declined types must not come
        // at all, and the graceful closure ends it.
        $this->assertSame([
            'notifications/subscriptions/acknowledged',
            'notifications/tools/list_changed',
            'notifications/resources/updated',
            'response',
        ], $methods);

        $this->assertSame(['toolsListChanged' => true, 'resourceSubscriptions' => ['file:///watched']], (array) $frames[0]['params']['notifications']);
        $this->assertSame('file:///watched', $frames[2]['params']['uri']);

        // Every message on the stream names the subscription it belongs to.
        foreach ([$frames[0], $frames[1], $frames[2]] as $frame) {
            $this->assertSame(42, $frame['params']['_meta'][RequestMeta::SUBSCRIPTION_ID]);
        }

        $this->assertSame(42, $frames[3]['id']);
        $this->assertSame(42, $frames[3]['result']['_meta'][RequestMeta::SUBSCRIPTION_ID]);
        $this->assertSame('complete', $frames[3]['result']['resultType']);
    }

    #[TestDox('the acknowledgment drops types the server cannot honour')]
    public function testAcknowledgmentReflectsWhatTheServerCanDo(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->setCapabilities(new ServerCapabilities(toolsListChanged: true, promptsListChanged: false))
            ->setNotificationBus(new InMemoryNotificationBus())
            ->setSubscriptionLifetime(0.05)
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $result = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => [
                'notifications' => ['toolsListChanged' => true, 'promptsListChanged' => true],
                '_meta' => [
                    RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                    RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR), [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'subscriptions/listen',
        ]);

        $first = null;
        $stream = $result->frames;
        $this->assertNotNull($stream);

        foreach ($stream() as $frame) {
            if (null !== $frame) {
                $first = $frame;
                break;
            }
        }

        $this->assertSame(['toolsListChanged' => true], (array) $first['params']['notifications']);
    }

    #[TestDox('an extension method is served by the extension that claims it')]
    public function testExtensionMethodIsServed(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->enableExtension(new ThingExtension())
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $answer = self::callWithHeaders($protocol, 'com.example/things.list', [], [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'com.example/things.list',
        ]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame(['a', 'b'], $answer['body']['result']['things']);
    }

    #[TestDox('the extension is advertised under capabilities.extensions')]
    public function testExtensionIsAdvertised(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->enableExtension(new ThingExtension())
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $answer = self::call($protocol, 'server/discover');

        $this->assertSame(['flavour' => 'vanilla'], (array) $answer['body']['result']['capabilities']['extensions']['com.example/things']);
    }

    #[TestDox('a method of an extension this server has never heard of stays generic')]
    public function testUnknownExtensionMethodStaysGeneric(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $answer = self::callWithHeaders($protocol, 'com.example/things.list', [], [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'com.example/things.list',
        ]);

        $this->assertSame(404, $answer['status']);
        $this->assertSame(Error::METHOD_NOT_FOUND, $answer['body']['error']['code']);
        // The extension was never enabled, so it never entered the method map
        // — there is nothing to name it by.
        $this->assertStringContainsString('com.example/things.list', $answer['body']['error']['message']);
        $this->assertStringNotContainsString('extension', $answer['body']['error']['message']);
    }

    #[TestDox('a method of an extension this server does not serve says so by name')]
    public function testUnservedExtensionMethodNamesItsExtension(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->enableExtension(new UnservedThingExtension())
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $answer = self::callWithHeaders($protocol, 'com.example/things.list', [], [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'com.example/things.list',
        ]);

        $this->assertSame(404, $answer['status']);
        $this->assertSame(Error::METHOD_NOT_FOUND, $answer['body']['error']['code']);
        $this->assertStringContainsString('com.example/unserved-things', $answer['body']['error']['message']);
    }

    #[TestDox('a notification is acknowledged with no body, never answered')]
    public function testNotificationIsAcknowledged(): void
    {
        $result = self::protocol()->handle(
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/something', 'params' => []], \JSON_THROW_ON_ERROR),
            ['Mcp-Method' => 'notifications/something'],
        );

        $this->assertTrue($result->isEmpty());
        $this->assertSame(202, $result->httpStatus);
    }

    #[TestDox('a notification for a removed method is refused with no body')]
    public function testRemovedNotificationIsRefused(): void
    {
        $result = self::protocol()->handle(
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], \JSON_THROW_ON_ERROR),
            ['Mcp-Method' => 'notifications/initialized'],
        );

        $this->assertTrue($result->isEmpty());
        $this->assertSame(400, $result->httpStatus);
    }

    #[TestDox('an error for a request whose id could not be read omits the id')]
    public function testUnreadableIdIsOmittedRatherThanEmptied(): void
    {
        $result = self::protocol()->handle('{"jsonrpc":"2.0","id":{"bad":1},"method":"tools/list"}', []);

        $body = json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('id', $body);
        $this->assertSame(Error::INVALID_REQUEST, $body['error']['code']);
    }

    #[TestDox('an error for a readable id still echoes it')]
    public function testReadableIdIsEchoed(): void
    {
        $answer = self::callWithHeaders(self::protocol(), 'tools/list', [], ['Mcp-Method' => 'tools/list']);

        $this->assertSame(1, $answer['body']['id']);
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
