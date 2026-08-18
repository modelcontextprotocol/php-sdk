<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The conformance fixture, for both protocol eras, on one endpoint.
 *
 * A server built through Builder::build() carries a dispatcher for each era and
 * StreamableHttpTransport routes every request to the one it belongs to, so
 * there is nothing here that a revision has to be told about. The element set
 * is the union of what both suites ask for: where they overlap they share the
 * registration, so a difference in results points at the lifecycle rather than
 * at drifted fixtures.
 */

ini_set('display_errors', '0');

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Capability\Registry;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Tests\Conformance\Elements;
use Mcp\Tests\Conformance\FileLogger;
use Mcp\Tests\Conformance\MrtrElements;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

chdir(__DIR__);

$logger = new FileLogger(__DIR__.'/logs/conformance.log', true);

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

// Explicit rather than builder-built, so the diagnostic hooks below can mutate
// the live registry and the change reaches an open subscription.
//
// Filesystem-backed and not in-memory: under php-fpm the worker holding the
// listen stream open and the worker serving the tools/call that mutates the
// registry are different processes, so the only thing they share is storage.
$bus = new Psr16NotificationBus(
    new Psr16Cache(new FilesystemAdapter('mcp-conformance-notifications', 120, __DIR__.'/sessions')),
    logger: $logger,
);
$registry = new Registry(new PublishingEventDispatcher($bus), $logger);

$server = Server::builder()
    ->setServerInfo('mcp-conformance-test-server', '1.0.0')
    ->setLogger($logger)
    ->setRegistry($registry)
    // Only the handshake leg keeps one; the modern leg is sessionless either way.
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    // Tools
    ->addTool(static fn () => 'This is a simple text response for testing.', name: 'test_simple_text', description: 'Tests simple text content response')
    ->addTool(static fn () => new ImageContent(Elements::TEST_IMAGE_BASE64, 'image/png'), name: 'test_image_content', description: 'Tests image content response')
    ->addTool(static fn () => new AudioContent(Elements::TEST_AUDIO_BASE64, 'audio/wav'), name: 'test_audio_content', description: 'Tests audio content response')
    ->addTool(static fn () => EmbeddedResource::fromText('test://embedded-resource', 'This is an embedded resource content.'), name: 'test_embedded_resource', description: 'Tests embedded resource content response')
    ->addTool([Elements::class, 'toolMultipleTypes'], name: 'test_multiple_content_types', description: 'Tests response with multiple content types')
    ->addTool(static fn () => CallToolResult::error([new TextContent('This tool intentionally returns an error for testing')]), name: 'test_error_handling', description: 'Tests error response handling')
    ->addTool(
        static fn () => 'ok',
        name: 'json_schema_2020_12_tool',
        description: 'Tool with JSON Schema 2020-12 features',
        inputSchema: Elements::jsonSchema2020_12Fixture(),
    )
    // Exercises the -32021 path.
    ->addTool(
        static function (): never {
            throw new MissingRequiredClientCapabilityException(new ClientCapabilities(roots: false, sampling: true), 'test_missing_capability requires the sampling capability.');
        },
        name: 'test_missing_capability',
        description: 'Always reports a missing client capability, for testing -32021 handling',
    )
    // The ask travels back inside the result, never as its own request.
    ->addTool(
        static fn (): InputRequiredResult => new InputRequiredResult(
            [
                'conformance_probe' => new ElicitRequest(
                    'Please provide a value for the conformance probe.',
                    new ElicitationSchema(['value' => new StringSchemaDefinition('Value')], ['value']),
                ),
            ],
            requestState: base64_encode(json_encode(['tool' => 'test_streaming_elicitation'], \JSON_THROW_ON_ERROR)),
        ),
        name: 'test_streaming_elicitation',
        description: 'Returns an InputRequiredResult asking for elicitation input',
    )
    // Logs server-side only; no logLevel was requested, so nothing goes out.
    ->addTool(
        static function () use ($logger): string {
            $logger->info('test_logging_tool executed');

            return 'Logged.';
        },
        name: 'test_logging_tool',
        description: 'Emits a server-side log message while returning normally',
    )
    // Mirrors its arguments into Mcp-Param-* headers (SEP-2243).
    ->addTool(
        static fn (string $region = '', int $retries = 0): string => sprintf('region=%s retries=%d', $region, $retries),
        name: 'test_custom_headers',
        description: 'Tests custom header mirroring via x-mcp-header',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'retries' => ['type' => 'integer', 'x-mcp-header' => 'Retries'],
            ],
            'required' => ['region'],
        ],
    )
    ->addTool([Elements::class, 'toolWithProgress'], name: 'test_tool_with_progress', description: 'Tests tool that reports progress notifications')
    // Handshake-era elements. The scenarios that drive them are 2025-11-25
    // ones — server-initiated requests and session-scoped logging both went
    // away in 2026-07-28 — but they are registered once, like everything else.
    ->addTool([Elements::class, 'toolWithLogging'], name: 'test_tool_with_logging', description: 'Tests tool that emits log messages')
    ->addTool([Elements::class, 'toolWithSampling'], name: 'test_sampling', description: 'Tests server-initiated sampling')
    ->addTool([Elements::class, 'toolWithElicitation'], name: 'test_elicitation', description: 'Tests server-initiated elicitation')
    ->addTool([Elements::class, 'toolWithElicitationDefaults'], name: 'test_elicitation_sep1034_defaults', description: 'Tests elicitation with default values')
    ->addTool([Elements::class, 'toolWithElicitationEnums'], name: 'test_elicitation_sep1330_enums', description: 'Tests elicitation with enum schemas')
    // Diagnostic hooks the subscription scenarios call to make the lists change
    // while a listen stream is open.
    ->addTool(
        static function () use ($registry): string {
            $registry->registerTool(
                new Tool(
                    name: 'test_ephemeral_tool_'.bin2hex(random_bytes(4)),
                    title: null,
                    inputSchema: ['type' => 'object', 'properties' => new stdClass(), 'required' => null],
                    description: 'Registered to trigger a list change',
                    annotations: null,
                ),
                static fn (): string => 'ephemeral',
            );

            return 'Tool list mutated.';
        },
        name: 'test_trigger_tool_change',
        description: 'Registers a tool so the tool list changes',
    )
    ->addTool(
        static function () use ($registry): string {
            $registry->registerPrompt(
                new Prompt('test_ephemeral_prompt_'.bin2hex(random_bytes(4)), null, 'Registered to trigger a list change'),
                static fn (): array => [['role' => 'user', 'content' => 'ephemeral']],
            );

            return 'Prompt list mutated.';
        },
        name: 'test_trigger_prompt_change',
        description: 'Registers a prompt so the prompt list changes',
    )
    // Multi round-trip request tools (SEP-2322).
    ->addTool([MrtrElements::class, 'elicitation'], name: 'test_input_required_result_elicitation', description: 'MRTR: asks for a name via elicitation')
    ->addTool([MrtrElements::class, 'sampling'], name: 'test_input_required_result_sampling', description: 'MRTR: asks for a sampling completion')
    ->addTool([MrtrElements::class, 'listRoots'], name: 'test_input_required_result_list_roots', description: 'MRTR: asks for the client roots')
    ->addTool([MrtrElements::class, 'elicitation'], name: 'test_input_required_result_request_state', description: 'MRTR: exercises requestState round-tripping')
    ->addTool([MrtrElements::class, 'multipleInputs'], name: 'test_input_required_result_multiple_inputs', description: 'MRTR: asks for two inputs at once')
    ->addTool([MrtrElements::class, 'multiRound'], name: 'test_input_required_result_multi_round', description: 'MRTR: asks across two sequential rounds')
    ->addTool([MrtrElements::class, 'capabilities'], name: 'test_input_required_result_capabilities', description: 'MRTR: asks only for capabilities the client declared')
    ->addTool([MrtrElements::class, 'tamperedState'], name: 'test_input_required_result_tampered_state', description: 'MRTR: completes only when the echoed state verifies')
    // Resources
    ->addResource(static fn () => 'This is the content of the static text resource.', 'test://static-text', 'static-text', 'A static text resource for testing')
    ->addResource(static fn () => fopen('data://image/png;base64,'.Elements::TEST_IMAGE_BASE64, 'r'), 'test://static-binary', 'static-binary', 'A static binary resource (image) for testing')
    ->addResourceTemplate([Elements::class, 'resourceTemplate'], 'test://template/{id}/data', 'template', 'A resource template with parameter substitution', 'application/json')
    ->addResource(static fn () => 'Watched resource content', 'test://watched-resource', 'watched-resource', 'A resource that can be watched')
    // Prompts
    ->addPrompt(static fn () => [['role' => 'user', 'content' => 'This is a simple prompt for testing.']], name: 'test_simple_prompt', description: 'A simple prompt without arguments')
    ->addPrompt([Elements::class, 'promptWithArguments'], name: 'test_prompt_with_arguments', description: 'A prompt with required arguments')
    ->addPrompt([Elements::class, 'promptWithEmbeddedResource'], name: 'test_prompt_with_embedded_resource', description: 'A prompt that includes an embedded resource')
    ->addPrompt([Elements::class, 'promptWithImage'], name: 'test_prompt_with_image', description: 'A prompt that includes image content')
    ->addPrompt([MrtrElements::class, 'prompt'], name: 'test_input_required_result_prompt', description: 'MRTR: a prompt that asks for input first')
    // Fixed so a retry landing on another process still verifies.
    ->setRequestState(str_repeat('conformance-fixture-key-', 2))
    // So a listen stream carries the registry's changes rather than only
    // acknowledging. In-memory is right here: the conformance server is one
    // FrankenPHP-less php-fpm pool, and the scenarios publish within a request.
    ->setNotificationBus($bus)
    // Short, so a listen stream cannot tie up an fpm worker for the length of
    // a whole run.
    ->setSubscriptionLifetime(5.0)
    // Lists are the same for everyone here; a read is not.
    ->setCachePolicy(
        CachePolicy::default(60_000)
            ->withMethod('tools/list', 3_600_000, CacheScope::Public)
            ->withMethod('prompts/list', 3_600_000, CacheScope::Public)
            ->withMethod('resources/list', 3_600_000, CacheScope::Public)
            ->withMethod('resources/templates/list', 3_600_000, CacheScope::Public)
            ->withMethod('server/discover', 3_600_000, CacheScope::Public),
    )
    ->build();

(new SapiEmitter())->emit($server->run(new StreamableHttpTransport($request, logger: $logger)));
