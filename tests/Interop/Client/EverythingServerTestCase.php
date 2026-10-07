<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Client;

use Mcp\Client;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\ResourceReference;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs our client against the reference server of the TypeScript SDK.
 *
 * Every other client test talks to a server built with this SDK, which shares
 * its schema classes and therefore its blind spots: a field both halves drop, or
 * a shape both halves accept but the spec does not, passes there. Here the other
 * half is `@modelcontextprotocol/server-everything`, installed with its whole
 * dependency tree locked (see {@see \Mcp\Tests\Interop\NodeTool}) so a snapshot only changes when
 * this SDK does.
 *
 * Each scenario makes two assertions:
 *
 *  - the snapshot holds what the server sent, as it arrived on the wire: the
 *    result, every request it sent back to the client while producing it, and
 *    its progress notifications. The snapshots are shared between transports:
 *    the same scenario must look the same over stdio and HTTP.
 *  - the round trip: what the client parsed, encoded again, must equal what
 *    arrived. A field our schema classes drop fails here instead of silently
 *    disappearing from the snapshot. Losses not fixed yet are listed in
 *    {@see self::KNOWN_LOSSES}, and must still occur for the test to pass.
 *
 * A missing snapshot is written on first run and the test marked incomplete;
 * delete a snapshot to re-record it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class EverythingServerTestCase extends TestCase
{
    private const TIMEOUT = 30;

    /**
     * Fields the client loses when parsing, per scenario, until they are fixed.
     * Paths use `*` for list indices.
     */
    private const KNOWN_LOSSES = [
        // Tool has no `execution` (2025-11-25 tasks).
        'tools_list' => ['missing result.tools.*.execution'],
        // ServerCapabilities has no `tasks` (2025-11-25 tasks).
        'initialize' => ['missing result.capabilities.tasks'],
    ];

    /**
     * Fields the client always writes, where the server may leave them out and
     * the specification gives the same meaning to their absence.
     */
    private const SPEC_DEFAULTS = [
        'isError' => false,
    ];

    private static ?Client $client = null;
    private static ?RecordingTransport $transport = null;

    private static ?\stdClass $initializeResult = null;

    /**
     * What the client's handlers were given, by JSON-RPC id.
     *
     * @var array<string, array{method: string, params: mixed}>
     */
    private static array $serverRequests = [];

    abstract protected static function transport(): TransportInterface;

    public static function setUpBeforeClass(): void
    {
        self::$client = Client::builder()
            ->setClientInfo('php-sdk-interop', '1.0.0')
            ->setInitTimeout(self::TIMEOUT)
            ->setRequestTimeout(self::TIMEOUT)
            ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true, sampling: true, elicitation: true))
            ->addRequestHandler(new SamplingRequestHandler(new class implements SamplingCallbackInterface {
                public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
                {
                    EverythingServerTestCase::record($request);

                    return new CreateSamplingMessageResult(Role::Assistant, new TextContent('A sampled answer.'), 'interop-model', 'endTurn');
                }
            }))
            ->addRequestHandler(new ElicitationRequestHandler(new class implements ElicitationCallbackInterface {
                public function __invoke(ElicitRequest $request): ElicitResult
                {
                    EverythingServerTestCase::record($request);

                    return new ElicitResult(ElicitAction::Accept, [
                        'name' => 'Ada Lovelace',
                        'check' => true,
                        'email' => 'ada@example.com',
                        'integer' => 42,
                        'untitledSingleSelectEnum' => 'Rachel',
                    ]);
                }
            }))
            ->addRequestHandler(new ListRootsRequestHandler(new class implements RootsCallbackInterface {
                public function __invoke(ListRootsRequest $request): ListRootsResult
                {
                    EverythingServerTestCase::record($request);

                    return new ListRootsResult([new Root('file:///workspace/app', 'Application')]);
                }
            }))
            ->build();

        self::$transport = new RecordingTransport(static::transport());
        self::$client->connect(self::$transport);

        foreach (self::$transport->received() as $message) {
            if (isset($message->result->serverInfo)) {
                self::$initializeResult = $message->result;
            }
        }

        // The server asks for the client's roots shortly after the handshake,
        // outside any request. Wait for it here, so it is not recorded as part
        // of whichever scenario happens to run when it arrives.
        if (static::receivesUnrelatedServerRequests()) {
            $deadline = microtime(true) + 5;
            while ([] === self::$serverRequests && microtime(true) < $deadline) {
                usleep(100_000);
                self::$client->ping();
            }
            self::$serverRequests = [];
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$client?->disconnect();
        self::$client = null;
        self::$transport = null;
    }

    /**
     * @internal called by the client's request handlers
     */
    public static function record(CreateSamplingMessageRequest|ElicitRequest|ListRootsRequest $request): void
    {
        $message = self::decode($request);

        self::$serverRequests[json_encode($message->id, \JSON_THROW_ON_ERROR)] = ['method' => $message->method, 'params' => $message->params ?? null];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideScenarios(): iterable
    {
        foreach ([
            'initialize', 'ping',
            'tools_list', 'tools_call-echo', 'tools_call-get_sum', 'tools_call-get_structured_content',
            'tools_call-get_tiny_image', 'tools_call-get_annotated_message', 'tools_call-get_resource_links',
            'tools_call-get_resource_reference', 'tools_call-long_running_operation',
            'tools_call-sampling', 'tools_call-elicitation', 'tools_call-roots', 'tools_call-unknown_tool',
            'resources_list', 'resources_templates_list', 'resources_read-static', 'resources_read-template',
            'prompts_list', 'prompts_get-without_arguments', 'prompts_get-with_arguments',
            'completion_complete-prompt', 'completion_complete-resource',
        ] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('provideScenarios')]
    public function testScenarioMatchesSnapshot(string $scenario): void
    {
        $client = self::$client ?? $this->fail('The client did not connect.');
        $transport = self::$transport ?? $this->fail('The client did not connect.');

        $transport->clear();
        self::$serverRequests = [];

        $parsed = $this->runScenario($scenario, $client);

        $received = $transport->received();
        $wire = $this->wire($scenario, $received);

        $this->assertMatchesSnapshot($scenario, $wire);
        $this->assertSame(
            self::KNOWN_LOSSES[$scenario] ?? [],
            $this->losses($wire, $parsed, $received),
            'What the client parsed differs from what the server sent. Fix the schema class, or, if the loss is known and tracked, list it in KNOWN_LOSSES.',
        );
    }

    /**
     * What the server sent during the scenario, as it arrived.
     *
     * @param list<\stdClass> $received
     *
     * @return array<string, mixed>
     */
    private function wire(string $scenario, array $received): array
    {
        $result = 'initialize' === $scenario ? self::$initializeResult : null;
        $requests = [];
        $progress = [];

        foreach ($received as $message) {
            if (isset($message->method, $message->id)) {
                $requests[] = ['method' => $message->method, 'params' => $message->params ?? null];
            } elseif ('notifications/progress' === ($message->method ?? null)) {
                // The token is the client's own, not something the server said.
                unset($message->params->progressToken);
                $progress[] = $message->params;
            } elseif (property_exists($message, 'result') || property_exists($message, 'error')) {
                $result = $message->result ?? (object) ['error' => $message->error];
            }
        }

        return array_filter([
            'result' => $result,
            'serverRequests' => $requests,
            'progress' => $progress,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * Where the client's parsed objects, encoded again, differ from the wire.
     *
     * Server requests are paired by JSON-RPC id, so one no handler recorded
     * cannot shift the rest onto the wrong partner.
     *
     * @param array<string, mixed> $wire
     * @param list<\stdClass>      $received
     *
     * @return list<string>
     */
    private function losses(array $wire, mixed $parsed, array $received): array
    {
        $losses = [];

        if (null !== $parsed) {
            $this->compare($wire['result'] ?? null, self::decode($parsed), 'result', $losses);
        }

        foreach ($received as $message) {
            if (!isset($message->method, $message->id)) {
                continue;
            }

            $handled = self::$serverRequests[json_encode($message->id, \JSON_THROW_ON_ERROR)] ?? null;
            if (null === $handled) {
                $losses[] = 'unrecorded serverRequest '.$message->method;
                continue;
            }

            $this->compare($message->params ?? null, $handled['params'], 'serverRequests.'.$message->method.'.params', $losses);
        }

        $losses = array_values(array_unique(array_map(
            static fn (string $loss): string => preg_replace('/\.\d+(?=\.|$)/', '.*', $loss),
            $losses,
        )));
        sort($losses);

        return $losses;
    }

    /**
     * Objects and lists are told apart, as are numbers and numeric strings;
     * only int and float are interchangeable, as JSON does not tell 1 from 1.0.
     *
     * @param list<string> $losses
     */
    private function compare(mixed $wire, mixed $parsed, string $path, array &$losses): void
    {
        if ($wire instanceof \stdClass && $parsed instanceof \stdClass) {
            $this->compareMembers(get_object_vars($wire), get_object_vars($parsed), $path, $losses, defaults: true);

            return;
        }

        if (\is_array($wire) && \is_array($parsed)) {
            $this->compareMembers($wire, $parsed, $path, $losses, defaults: false);

            return;
        }

        $numbers = (\is_int($wire) || \is_float($wire)) && (\is_int($parsed) || \is_float($parsed));
        if ($numbers ? $wire != $parsed : $wire !== $parsed) {
            $losses[] = 'changed '.$path;
        }
    }

    /**
     * @param array<array-key, mixed> $wire
     * @param array<array-key, mixed> $parsed
     * @param list<string>            $losses
     */
    private function compareMembers(array $wire, array $parsed, string $path, array &$losses, bool $defaults): void
    {
        foreach ($wire as $key => $value) {
            if (!\array_key_exists($key, $parsed)) {
                $losses[] = \sprintf('missing %s.%s', $path, $key);
                continue;
            }

            $this->compare($value, $parsed[$key], $path.'.'.$key, $losses);
        }

        foreach ($parsed as $key => $value) {
            $isDefault = $defaults && \array_key_exists($key, self::SPEC_DEFAULTS) && self::SPEC_DEFAULTS[$key] === $value;
            if (!\array_key_exists($key, $wire) && !$isDefault) {
                $losses[] = \sprintf('added %s.%s', $path, $key);
            }
        }
    }

    /**
     * Encoded and decoded again, into objects like the wire messages.
     */
    private static function decode(mixed $value): mixed
    {
        return json_decode(json_encode($value, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
    }

    private function runScenario(string $scenario, Client $client): mixed
    {
        // The server only reports progress to a client asking for it.
        $onProgress = static function (): void {};

        return match ($scenario) {
            // The client keeps no more than this of the handshake; parse it the way it does.
            'initialize' => InitializeResult::fromArray(json_decode(json_encode(self::$initializeResult, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR)),
            // Nothing to parse.
            'ping' => $client->ping(),
            'tools_list' => $client->listTools(),
            'tools_call-echo' => $client->callTool('echo', ['message' => 'Hello from PHP']),
            'tools_call-get_sum' => $client->callTool('get-sum', ['a' => 2, 'b' => 40]),
            'tools_call-get_structured_content' => $client->callTool('get-structured-content', ['location' => 'Chicago']),
            'tools_call-get_tiny_image' => $client->callTool('get-tiny-image'),
            'tools_call-get_annotated_message' => $client->callTool('get-annotated-message', ['messageType' => 'error', 'includeImage' => true]),
            'tools_call-get_resource_links' => $client->callTool('get-resource-links', ['count' => 2]),
            'tools_call-get_resource_reference' => $client->callTool('get-resource-reference', ['resourceType' => 'Text', 'resourceId' => 3]),
            'tools_call-long_running_operation' => $client->callTool('trigger-long-running-operation', ['duration' => 1, 'steps' => 2], $onProgress),
            'tools_call-sampling' => $client->callTool('trigger-sampling-request', ['prompt' => 'Say hello', 'maxTokens' => 20]),
            'tools_call-elicitation' => $client->callTool('trigger-elicitation-request'),
            'tools_call-roots' => $this->callRootsTool($client),
            'tools_call-unknown_tool' => $client->callTool('does-not-exist'),
            'resources_list' => $client->listResources(),
            'resources_templates_list' => $client->listResourceTemplates(),
            'resources_read-static' => $client->readResource('demo://resource/static/document/architecture.md'),
            'resources_read-template' => $client->readResource('demo://resource/dynamic/text/7'),
            'prompts_list' => $client->listPrompts(),
            'prompts_get-without_arguments' => $client->getPrompt('simple-prompt'),
            'prompts_get-with_arguments' => $client->getPrompt('args-prompt', ['city' => 'Berlin', 'state' => 'Berlin']),
            'completion_complete-prompt' => $client->complete(new PromptReference('completable-prompt'), ['name' => 'department', 'value' => 'E']),
            'completion_complete-resource' => $client->complete(new ResourceReference('demo://resource/dynamic/text/{resourceId}'), ['name' => 'resourceId', 'value' => '1']),
            default => throw new \LogicException(\sprintf('Unknown scenario "%s".', $scenario)),
        };
    }

    /**
     * Whether the server can reach the client with a request that is not part
     * of a request the client sent.
     */
    protected static function receivesUnrelatedServerRequests(): bool
    {
        return true;
    }

    private function callRootsTool(Client $client): mixed
    {
        // The server asks for roots outside the tool call it is answering. Over
        // Streamable HTTP that request travels on the standalone GET stream,
        // which the HTTP transport does not open yet.
        if (!static::receivesUnrelatedServerRequests()) {
            $this->markTestSkipped('The client transport does not receive server requests sent outside of a client request.');
        }

        return $client->callTool('get-roots-list');
    }

    private function assertMatchesSnapshot(string $scenario, mixed $data): void
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        // The dynamic resources stamp the time they were generated into their text.
        $json = preg_replace('/\d{1,2}:\d{2}:\d{2}(?:\s?[AP]M)?/u', '<time>', $json);

        $file = __DIR__.'/snapshots/'.$scenario.'.json';

        if (!file_exists($file)) {
            @mkdir(\dirname($file), 0777, true);
            file_put_contents($file, $json.\PHP_EOL);
            $this->markTestIncomplete(\sprintf('Snapshot created at %s, please re-run tests.', $file));
        }

        $this->assertJsonStringEqualsJsonString((string) file_get_contents($file), $json, \sprintf('Result does not match snapshot "%s".', $file));
    }
}
