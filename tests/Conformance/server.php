<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

ini_set('display_errors', '0');

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Tests\Conformance\Elements;
use Mcp\Tests\Conformance\FileLogger;

chdir(__DIR__);

$logger = new FileLogger(__DIR__.'/logs/conformance.log', true);

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$transport = new StreamableHttpTransport($request, logger: $logger);

$server = Server::builder()
    ->setServerInfo('mcp-conformance-test-server', '1.0.0')
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger($logger)
    // Tools
    ->addTool(static fn () => 'This is a simple text response for testing.', name: 'test_simple_text', description: 'Tests simple text content response')
    ->addTool(static fn () => new ImageContent(Elements::TEST_IMAGE_BASE64, 'image/png'), name: 'test_image_content', description: 'Tests image content response')
    ->addTool(static fn () => new AudioContent(Elements::TEST_AUDIO_BASE64, 'audio/wav'), name: 'test_audio_content', description: 'Tests audio content response')
    ->addTool(static fn () => EmbeddedResource::fromText('test://embedded-resource', 'This is an embedded resource content.'), name: 'test_embedded_resource', description: 'Tests embedded resource content response')
    ->addTool([Elements::class, 'toolMultipleTypes'], name: 'test_multiple_content_types', description: 'Tests response with multiple content types')
    ->addTool([Elements::class, 'toolWithLogging'], name: 'test_tool_with_logging', description: 'Tests tool that emits log messages')
    ->addTool([Elements::class, 'toolWithProgress'], name: 'test_tool_with_progress', description: 'Tests tool that reports progress notifications')
    ->addTool([Elements::class, 'toolWithSampling'], name: 'test_sampling', description: 'Tests server-initiated sampling')
    ->addTool(static fn () => CallToolResult::error([new TextContent('This tool intentionally returns an error for testing')]), name: 'test_error_handling', description: 'Tests error response handling')
    ->addTool([Elements::class, 'toolWithElicitation'], name: 'test_elicitation', description: 'Tests server-initiated elicitation')
    ->addTool([Elements::class, 'toolWithElicitationDefaults'], name: 'test_elicitation_sep1034_defaults', description: 'Tests elicitation with default values')
    ->addTool([Elements::class, 'toolWithElicitationEnums'], name: 'test_elicitation_sep1330_enums', description: 'Tests elicitation with enum schemas')
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
    ->build();

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
