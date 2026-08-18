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
 * Conformance fixture for the modern (SEP-2575) lifecycle, served at
 * /stateless alongside the handshake-era fixture at /. The element set is
 * deliberately the same as server.php's where the two eras overlap, so that a
 * difference in conformance results points at the lifecycle rather than at the
 * fixtures having drifted apart.
 */

ini_set('display_errors', '0');

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Tests\Conformance\Elements;
use Mcp\Tests\Conformance\FileLogger;

chdir(__DIR__);

$logger = new FileLogger(__DIR__.'/logs/conformance-stateless.log', true);

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$protocol = Server::builder()
    ->setServerInfo('mcp-conformance-test-server', '1.0.0')
    ->setLogger($logger)
    // Tools
    ->addTool(static fn () => 'This is a simple text response for testing.', name: 'test_simple_text', description: 'Tests simple text content response')
    ->addTool(static fn () => new ImageContent(Elements::TEST_IMAGE_BASE64, 'image/png'), name: 'test_image_content', description: 'Tests image content response')
    ->addTool(static fn () => new AudioContent(Elements::TEST_AUDIO_BASE64, 'audio/wav'), name: 'test_audio_content', description: 'Tests audio content response')
    ->addTool(static fn () => EmbeddedResource::fromText('test://embedded-resource', 'This is an embedded resource content.'), name: 'test_embedded_resource', description: 'Tests embedded resource content response')
    ->addTool([Elements::class, 'toolMultipleTypes'], name: 'test_multiple_content_types', description: 'Tests response with multiple content types')
    ->addTool(static fn () => CallToolResult::error([new TextContent('This tool intentionally returns an error for testing')]), name: 'test_error_handling', description: 'Tests error response handling')
    // Exercises the -32021 path: the tool needs to call back into the client,
    // which a client that never declared `sampling` cannot service.
    ->addTool(
        static function (): never {
            throw new MissingRequiredClientCapabilityException(new ClientCapabilities(roots: false, sampling: true), 'test_missing_capability requires the sampling capability.');
        },
        name: 'test_missing_capability',
        description: 'Always reports a missing client capability, for testing -32021 handling',
    )
    // Asks for input the MRTR way: the ask travels back inside the result, so
    // the response stream never carries a server-initiated request.
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
    // The logger writes to the fixture's log file, never to the wire: a modern
    // server emits notifications/message only when the request asked for a log
    // level, and this one deliberately does not.
    ->addTool(
        static function () use ($logger): string {
            $logger->info('test_logging_tool executed');

            return 'Logged.';
        },
        name: 'test_logging_tool',
        description: 'Emits a server-side log message while returning normally',
    )
    // Mirrors its arguments into Mcp-Param-* headers (SEP-2243), so the header/
    // body agreement rules have something to be checked against.
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
    // Resources
    ->addResource(static fn () => 'This is the content of the static text resource.', 'test://static-text', 'static-text', 'A static text resource for testing')
    ->addResource(static fn () => fopen('data://image/png;base64,'.Elements::TEST_IMAGE_BASE64, 'r'), 'test://static-binary', 'static-binary', 'A static binary resource (image) for testing')
    ->addResourceTemplate([Elements::class, 'resourceTemplate'], 'test://template/{id}/data', 'template', 'A resource template with parameter substitution', 'application/json')
    // Prompts
    ->addPrompt(static fn () => [['role' => 'user', 'content' => 'This is a simple prompt for testing.']], name: 'test_simple_prompt', description: 'A simple prompt without arguments')
    ->addPrompt([Elements::class, 'promptWithArguments'], name: 'test_prompt_with_arguments', description: 'A prompt with required arguments')
    ->buildStateless([ProtocolVersion::V2026_07_28]);

$transport = new StatelessHttpTransport($protocol, logger: $logger);

(new SapiEmitter())->emit($transport->handle($request));
